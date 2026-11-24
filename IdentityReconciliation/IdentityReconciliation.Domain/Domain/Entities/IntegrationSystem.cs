using IdentityReconciliation.Domain.Enums;

namespace IdentityReconciliation.Domain.Entities;

public class IntegrationSystem
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public string Name { get; set; } = string.Empty;
    public IntegrationSystemType Type { get; set; }
    public string BaseUrl { get; set; } = string.Empty;
    public bool IsEnabled { get; set; } = true;
    public ProvisioningMode ProvisioningMode { get; set; } = ProvisioningMode.ObserveOnly;
    public bool AllowAccountCreation { get; set; }
    public bool AllowFirstLoginClaim { get; set; }
    public string? EncryptedConfiguration { get; set; }
    public DateTime? LastHealthCheckAt { get; set; }
    public bool? LastHealthCheckSucceeded { get; set; }
    public string? LastHealthCheckMessage { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public DateTime UpdatedAt { get; set; } = DateTime.UtcNow;
}
