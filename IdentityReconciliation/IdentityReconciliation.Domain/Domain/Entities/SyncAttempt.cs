namespace IdentityReconciliation.Domain.Entities;

public class SyncAttempt
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid SyncOperationId { get; set; }
    public SyncOperation? SyncOperation { get; set; }
    public int AttemptNumber { get; set; }
    public int? ResponseStatusCode { get; set; }
    public long DurationMilliseconds { get; set; }
    public bool Succeeded { get; set; }
    public string? ErrorCategory { get; set; }
    public string? SanitizedMessage { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
}
