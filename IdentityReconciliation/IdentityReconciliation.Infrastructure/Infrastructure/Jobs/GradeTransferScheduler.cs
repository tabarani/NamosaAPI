using IdentityReconciliation.Application.Interfaces;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace IdentityReconciliation.Infrastructure.Jobs;

public sealed class GradeTransferScheduler : BackgroundService
{
    private readonly IServiceScopeFactory _scopeFactory;
    private readonly ILogger<GradeTransferScheduler> _logger;
    public GradeTransferScheduler(IServiceScopeFactory scopeFactory, ILogger<GradeTransferScheduler> logger)
    {
        _scopeFactory = scopeFactory;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        using var timer = new PeriodicTimer(TimeSpan.FromHours(1));
        await CheckAsync(stoppingToken);
        while (await timer.WaitForNextTickAsync(stoppingToken))
            await CheckAsync(stoppingToken);
    }

    private async Task CheckAsync(CancellationToken cancellationToken)
    {
        try
        {
            await using var scope = _scopeFactory.CreateAsyncScope();
            var transfer = scope.ServiceProvider.GetRequiredService<IGradeTransferService>();
            var settings = scope.ServiceProvider.GetRequiredService<IControlCenterSettings>();
            var policy = await transfer.GetPolicyAsync(cancellationToken);
            if (!policy.Enabled || !policy.ScheduledTransferEnabled || !policy.IsWindowOpen(DateTimeOffset.UtcNow)) return;
            var lastKey = await settings.GetAsync("GradeTransfer", "LastScheduledSemesterKey", cancellationToken);
            if (string.Equals(lastKey, policy.SemesterKey, StringComparison.OrdinalIgnoreCase)) return;
            var result = await transfer.RunAsync(null, false, "Yulpana scheduler", cancellationToken);
            var hasBlockingStatus = result.Courses.Any(x => x.Status is "BlockedIncomplete" or "MappingRequired" or "TransferFailed");
            if (!hasBlockingStatus)
                await settings.SetAsync("GradeTransfer", "LastScheduledSemesterKey", policy.SemesterKey, false, "Yulpana scheduler", cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Scheduled Moodle-to-Gibbon grade transfer check failed.");
        }
    }
}
