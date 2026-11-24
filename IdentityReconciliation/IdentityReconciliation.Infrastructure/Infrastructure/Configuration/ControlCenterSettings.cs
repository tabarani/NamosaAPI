using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Infrastructure.Data;
using Microsoft.AspNetCore.DataProtection;
using Microsoft.EntityFrameworkCore;

namespace IdentityReconciliation.Infrastructure.Configuration;

public sealed class ControlCenterSettings : IControlCenterSettings
{
    private readonly AppDbContext _db;
    private readonly IDataProtector _protector;

    public ControlCenterSettings(AppDbContext db, IDataProtectionProvider provider)
    {
        _db = db;
        _protector = provider.CreateProtector("Yulpana.ControlCenter.Settings.v1");
    }

    public async Task<string?> GetAsync(string section, string key, CancellationToken cancellationToken = default)
    {
        var setting = await _db.ControlCenterSettings.AsNoTracking()
            .SingleOrDefaultAsync(x => x.Section == section && x.Key == key, cancellationToken);

        if (setting?.Value is null)
            return null;

        return setting.IsSecret ? _protector.Unprotect(setting.Value) : setting.Value;
    }

    public async Task SetAsync(string section, string key, string? value, bool isSecret, string? updatedBy, CancellationToken cancellationToken = default)
    {
        var setting = await _db.ControlCenterSettings
            .SingleOrDefaultAsync(x => x.Section == section && x.Key == key, cancellationToken);

        if (setting is null)
        {
            setting = new ControlCenterSetting { Section = section, Key = key };
            _db.ControlCenterSettings.Add(setting);
        }

        setting.IsSecret = isSecret;
        setting.Value = value is null ? null : isSecret ? _protector.Protect(value) : value;
        setting.UpdatedAt = DateTime.UtcNow;
        setting.UpdatedBy = updatedBy;
        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task<IReadOnlyDictionary<string, string?>> GetSectionAsync(string section, CancellationToken cancellationToken = default)
    {
        var settings = await _db.ControlCenterSettings.AsNoTracking()
            .Where(x => x.Section == section)
            .ToListAsync(cancellationToken);

        return settings.ToDictionary(
            x => x.Key,
            x => x.Value is null ? null : x.IsSecret ? _protector.Unprotect(x.Value) : x.Value,
            StringComparer.OrdinalIgnoreCase);
    }
}
