using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Enums;

namespace IdentityReconciliation.Application.Interfaces;

public interface IControlCenterSettings
{
    Task<string?> GetAsync(string section, string key, CancellationToken cancellationToken = default);
    Task SetAsync(string section, string key, string? value, bool isSecret, string? updatedBy, CancellationToken cancellationToken = default);
    Task<IReadOnlyDictionary<string, string?>> GetSectionAsync(string section, CancellationToken cancellationToken = default);
}

public interface IIntegrationSystemService
{
    Task<IReadOnlyList<IntegrationSystem>> GetAllAsync(CancellationToken cancellationToken = default);
    Task<IntegrationSystem?> GetAsync(Guid id, CancellationToken cancellationToken = default);
    Task SaveAsync(IntegrationSystem system, IReadOnlyDictionary<string, string?> secrets, string? actor, CancellationToken cancellationToken = default);
    Task<IntegrationHealthResult> CheckHealthAsync(Guid id, CancellationToken cancellationToken = default);
}

public sealed record IntegrationHealthResult(bool Succeeded, string Message, DateTime CheckedAt);

public interface IIntegrationConnectionResolver
{
    Task<IntegrationConnection?> ResolveAsync(IntegrationSystemType type, CancellationToken cancellationToken = default);
}

public sealed record IntegrationConnection(
    Guid Id,
    string Name,
    IntegrationSystemType Type,
    string BaseUrl,
    ProvisioningMode ProvisioningMode,
    bool AllowAccountCreation,
    bool AllowFirstLoginClaim,
    IReadOnlyDictionary<string, string?> Secrets);

public interface ISyncJobService
{
    Task<SyncJob> CreateAsync(string jobType, string direction, string trigger, string? requestedBy, string? policySnapshot, CancellationToken cancellationToken = default);
    Task<SyncOperation> AddOperationAsync(Guid jobId, string entityType, string action, string sourceSystem, string? sourceId, string targetSystem, string? targetId, string idempotencyKey, string? intendedChange, CancellationToken cancellationToken = default);
    Task RecordAttemptAsync(Guid operationId, bool succeeded, int? responseStatusCode, long durationMilliseconds, string? errorCategory, string? sanitizedMessage, DateTime? nextRetryAt, CancellationToken cancellationToken = default);
    Task<IReadOnlyList<SyncJob>> GetRecentAsync(int count = 50, CancellationToken cancellationToken = default);
    Task<SyncJob?> GetAsync(Guid id, CancellationToken cancellationToken = default);
}
