using IdentityReconciliation.Domain.Enums;
using Microsoft.AspNetCore.Identity;

namespace IdentityReconciliation.Infrastructure.Identity;

public class ApplicationUser : IdentityUser
{
    public bool MustChangePassword { get; set; }
    public MigrationStatus MigrationStatus { get; set; } = MigrationStatus.NotStarted;
    public string? MigrationSource { get; set; }
    public DateTime? MigratedAt { get; set; }
    public DateTime? LastPasswordChangedAt { get; set; }
    public DateTime? LastSuccessfulLoginAt { get; set; }
    public DateTime? LastFailedLoginAt { get; set; }
    public bool IsProvisioningBlocked { get; set; }
    public string PreferredLanguage { get; set; } = "en";
}
