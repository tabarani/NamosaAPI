namespace IdentityReconciliation.Domain.Entities;

public class ControlCenterSetting
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public string Section { get; set; } = string.Empty;
    public string Key { get; set; } = string.Empty;
    public string? Value { get; set; }
    public bool IsSecret { get; set; }
    public DateTime UpdatedAt { get; set; } = DateTime.UtcNow;
    public string? UpdatedBy { get; set; }
}
