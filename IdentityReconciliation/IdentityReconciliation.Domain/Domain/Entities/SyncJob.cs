using IdentityReconciliation.Domain.Enums;

namespace IdentityReconciliation.Domain.Entities;

public class SyncJob
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public string JobType { get; set; } = string.Empty;
    public string Direction { get; set; } = string.Empty;
    public string Trigger { get; set; } = "Manual";
    public SyncJobStatus Status { get; set; } = SyncJobStatus.Pending;
    public string? RequestedBy { get; set; }
    public string CorrelationId { get; set; } = Guid.NewGuid().ToString("N");
    public string? PolicySnapshot { get; set; }
    public int TotalOperations { get; set; }
    public int SucceededOperations { get; set; }
    public int FailedOperations { get; set; }
    public int SkippedOperations { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public DateTime? StartedAt { get; set; }
    public DateTime? CompletedAt { get; set; }
    public ICollection<SyncOperation> Operations { get; set; } = new List<SyncOperation>();
}
