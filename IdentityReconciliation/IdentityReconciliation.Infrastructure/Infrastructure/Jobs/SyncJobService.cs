using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Enums;
using IdentityReconciliation.Infrastructure.Data;
using Microsoft.EntityFrameworkCore;

namespace IdentityReconciliation.Infrastructure.Jobs;

public sealed class SyncJobService : ISyncJobService
{
    private readonly AppDbContext _db;

    public SyncJobService(AppDbContext db) => _db = db;

    public async Task<SyncJob> CreateAsync(string jobType, string direction, string trigger, string? requestedBy, string? policySnapshot, CancellationToken cancellationToken = default)
    {
        var job = new SyncJob
        {
            JobType = jobType,
            Direction = direction,
            Trigger = trigger,
            RequestedBy = requestedBy,
            PolicySnapshot = policySnapshot
        };
        _db.SyncJobs.Add(job);
        await _db.SaveChangesAsync(cancellationToken);
        return job;
    }

    public async Task<SyncOperation> AddOperationAsync(Guid jobId, string entityType, string action, string sourceSystem, string? sourceId, string targetSystem, string? targetId, string idempotencyKey, string? intendedChange, CancellationToken cancellationToken = default)
    {
        var existing = await _db.SyncOperations.SingleOrDefaultAsync(x => x.IdempotencyKey == idempotencyKey, cancellationToken);
        if (existing is not null)
            return existing;

        var operation = new SyncOperation
        {
            SyncJobId = jobId,
            EntityType = entityType,
            Action = action,
            SourceSystem = sourceSystem,
            SourceId = sourceId,
            TargetSystem = targetSystem,
            TargetId = targetId,
            IdempotencyKey = idempotencyKey,
            IntendedChange = intendedChange
        };
        _db.SyncOperations.Add(operation);
        var job = await _db.SyncJobs.SingleAsync(x => x.Id == jobId, cancellationToken);
        job.TotalOperations++;
        await _db.SaveChangesAsync(cancellationToken);
        return operation;
    }

    public async Task RecordAttemptAsync(Guid operationId, bool succeeded, int? responseStatusCode, long durationMilliseconds, string? errorCategory, string? sanitizedMessage, DateTime? nextRetryAt, CancellationToken cancellationToken = default)
    {
        var operation = await _db.SyncOperations.Include(x => x.SyncJob).SingleAsync(x => x.Id == operationId, cancellationToken);
        operation.AttemptCount++;
        operation.StartedAt ??= DateTime.UtcNow;
        operation.ErrorCategory = errorCategory;
        operation.ErrorMessage = sanitizedMessage;
        operation.NextRetryAt = nextRetryAt;
        operation.Status = succeeded ? SyncOperationStatus.Succeeded
            : nextRetryAt.HasValue ? SyncOperationStatus.RetryScheduled
            : errorCategory == "Conflict" || errorCategory == "Validation" ? SyncOperationStatus.NeedsReview
            : SyncOperationStatus.Failed;
        if (succeeded || operation.Status is SyncOperationStatus.Failed or SyncOperationStatus.NeedsReview)
            operation.CompletedAt = DateTime.UtcNow;

        _db.SyncAttempts.Add(new SyncAttempt
        {
            SyncOperationId = operation.Id,
            AttemptNumber = operation.AttemptCount,
            ResponseStatusCode = responseStatusCode,
            DurationMilliseconds = durationMilliseconds,
            Succeeded = succeeded,
            ErrorCategory = errorCategory,
            SanitizedMessage = sanitizedMessage
        });

        var job = operation.SyncJob!;
        var statuses = await _db.SyncOperations.Where(x => x.SyncJobId == job.Id && x.Id != operation.Id).Select(x => x.Status).ToListAsync(cancellationToken);
        statuses.Add(operation.Status);
        job.SucceededOperations = statuses.Count(x => x == SyncOperationStatus.Succeeded);
        job.FailedOperations = statuses.Count(x => x == SyncOperationStatus.Failed);
        job.SkippedOperations = statuses.Count(x => x == SyncOperationStatus.Skipped);
        job.Status = statuses.Any(x => x is SyncOperationStatus.Pending or SyncOperationStatus.Running or SyncOperationStatus.RetryScheduled)
            ? SyncJobStatus.Running
            : statuses.Any(x => x == SyncOperationStatus.NeedsReview) ? SyncJobStatus.NeedsReview
            : statuses.All(x => x == SyncOperationStatus.Succeeded) ? SyncJobStatus.Succeeded
            : statuses.Any(x => x == SyncOperationStatus.Succeeded) ? SyncJobStatus.PartiallySucceeded
            : SyncJobStatus.Failed;
        if (job.Status is SyncJobStatus.Succeeded or SyncJobStatus.PartiallySucceeded or SyncJobStatus.NeedsReview or SyncJobStatus.Failed)
            job.CompletedAt = DateTime.UtcNow;

        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<SyncJob>> GetRecentAsync(int count = 50, CancellationToken cancellationToken = default) =>
        await _db.SyncJobs.AsNoTracking().OrderByDescending(x => x.CreatedAt).Take(Math.Clamp(count, 1, 200)).ToListAsync(cancellationToken);

    public Task<SyncJob?> GetAsync(Guid id, CancellationToken cancellationToken = default) =>
        _db.SyncJobs.AsNoTracking().Include(x => x.Operations).ThenInclude(x => x.Attempts).SingleOrDefaultAsync(x => x.Id == id, cancellationToken);
}
