namespace IdentityReconciliation.Domain.Entities;

public class AuditEvent
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public string Category { get; set; } = string.Empty;
    public string Action { get; set; } = string.Empty;
    public string? ActorId { get; set; }
    public string? SubjectType { get; set; }
    public string? SubjectId { get; set; }
    public string? CorrelationId { get; set; }
    public string? Details { get; set; }
    public string? IpAddress { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
}
