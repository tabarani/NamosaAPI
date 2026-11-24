using IdentityReconciliation.Domain.Enums;

namespace IdentityReconciliation.Domain.Entities;

public class SyncOperation
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid SyncJobId { get; set; }
    public SyncJob? SyncJob { get; set; }
    public string EntityType { get; set; } = string.Empty;
    public string Action { get; set; } = string.Empty;
    public string SourceSystem { get; set; } = string.Empty;
    public string? SourceId { get; set; }
    public string TargetSystem { get; set; } = string.Empty;
    public string? TargetId { get; set; }
    public string IdempotencyKey { get; set; } = string.Empty;
    public SyncOperationStatus Status { get; set; } = SyncOperationStatus.Pending;
    public string? BeforeSnapshot { get; set; }
    public string? IntendedChange { get; set; }
    public string? AfterSnapshot { get; set; }
    public int AttemptCount { get; set; }
    public DateTime? NextRetryAt { get; set; }
    public string? ErrorCategory { get; set; }
    public string? ErrorMessage { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public DateTime? StartedAt { get; set; }
    public DateTime? CompletedAt { get; set; }
    public ICollection<SyncAttempt> Attempts { get; set; } = new List<SyncAttempt>();
}
