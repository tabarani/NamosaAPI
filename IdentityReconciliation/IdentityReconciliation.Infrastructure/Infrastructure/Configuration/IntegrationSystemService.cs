using System.Diagnostics;
using System.Net.Http.Json;
using System.Text.Json;
using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Enums;
using IdentityReconciliation.Infrastructure.Data;
using Microsoft.AspNetCore.DataProtection;
using Microsoft.EntityFrameworkCore;

namespace IdentityReconciliation.Infrastructure.Configuration;

public sealed class IntegrationSystemService : IIntegrationSystemService, IIntegrationConnectionResolver
{
    private readonly AppDbContext _db;
    private readonly IHttpClientFactory _httpClientFactory;
    private readonly IDataProtector _protector;

    public IntegrationSystemService(AppDbContext db, IHttpClientFactory httpClientFactory, IDataProtectionProvider provider)
    {
        _db = db;
        _httpClientFactory = httpClientFactory;
        _protector = provider.CreateProtector("Yulpana.ControlCenter.IntegrationSecrets.v1");
    }

    public async Task<IReadOnlyList<IntegrationSystem>> GetAllAsync(CancellationToken cancellationToken = default) =>
        await _db.IntegrationSystems.AsNoTracking().OrderBy(x => x.Type).ThenBy(x => x.Name).ToListAsync(cancellationToken);

    public Task<IntegrationSystem?> GetAsync(Guid id, CancellationToken cancellationToken = default) =>
        _db.IntegrationSystems.AsNoTracking().SingleOrDefaultAsync(x => x.Id == id, cancellationToken);

    public async Task<IntegrationConnection?> ResolveAsync(IntegrationSystemType type, CancellationToken cancellationToken = default)
    {
        var system = await _db.IntegrationSystems.AsNoTracking()
            .Where(x => x.Type == type && x.IsEnabled)
            .OrderBy(x => x.CreatedAt)
            .FirstOrDefaultAsync(cancellationToken);
        if (system is null)
            return null;

        IReadOnlyDictionary<string, string?> secrets = new Dictionary<string, string?>();
        if (!string.IsNullOrWhiteSpace(system.EncryptedConfiguration))
        {
            var json = _protector.Unprotect(system.EncryptedConfiguration);
            secrets = JsonSerializer.Deserialize<Dictionary<string, string?>>(json)
                ?? new Dictionary<string, string?>();
        }

        return new IntegrationConnection(system.Id, system.Name, system.Type, system.BaseUrl,
            system.ProvisioningMode, system.AllowAccountCreation, system.AllowFirstLoginClaim, secrets);
    }

    public async Task SaveAsync(IntegrationSystem system, IReadOnlyDictionary<string, string?> secrets, string? actor, CancellationToken cancellationToken = default)
    {
        var existing = await _db.IntegrationSystems.SingleOrDefaultAsync(x => x.Id == system.Id, cancellationToken);
        var entity = existing ?? system;
        if (existing is not null)
        {
            existing.Name = system.Name;
            existing.Type = system.Type;
            existing.BaseUrl = system.BaseUrl.TrimEnd('/');
            existing.IsEnabled = system.IsEnabled;
            existing.ProvisioningMode = system.ProvisioningMode;
            existing.AllowAccountCreation = system.AllowAccountCreation;
            existing.AllowFirstLoginClaim = system.AllowFirstLoginClaim;
        }
        else
        {
            entity.BaseUrl = entity.BaseUrl.TrimEnd('/');
            _db.IntegrationSystems.Add(entity);
        }

        if (secrets.Count > 0)
            entity.EncryptedConfiguration = _protector.Protect(JsonSerializer.Serialize(secrets));

        entity.UpdatedAt = DateTime.UtcNow;
        _db.AuditEvents.Add(new AuditEvent
        {
            Category = "Configuration",
            Action = existing is null ? "IntegrationCreated" : "IntegrationUpdated",
            ActorId = actor,
            SubjectType = nameof(IntegrationSystem),
            SubjectId = entity.Id.ToString(),
            Details = JsonSerializer.Serialize(new { entity.Name, entity.Type, entity.BaseUrl, entity.ProvisioningMode })
        });
        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task<IntegrationHealthResult> CheckHealthAsync(Guid id, CancellationToken cancellationToken = default)
    {
        var system = await _db.IntegrationSystems.SingleAsync(x => x.Id == id, cancellationToken);
        var checkedAt = DateTime.UtcNow;
        try
        {
            using var client = _httpClientFactory.CreateClient("IntegrationHealth");
            var target = system.Type == IntegrationSystemType.Moodle
                ? $"{system.BaseUrl.TrimEnd('/')}/lib/ajax/service.php"
                : system.BaseUrl;
            using var response = await client.GetAsync(target, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
            system.LastHealthCheckSucceeded = response.IsSuccessStatusCode || (int)response.StatusCode is >= 300 and < 500;
            system.LastHealthCheckMessage = $"HTTP {(int)response.StatusCode} {response.ReasonPhrase}";
        }
        catch (Exception ex)
        {
            system.LastHealthCheckSucceeded = false;
            system.LastHealthCheckMessage = ex.Message.Length > 500 ? ex.Message[..500] : ex.Message;
        }

        system.LastHealthCheckAt = checkedAt;
        system.UpdatedAt = checkedAt;
        await _db.SaveChangesAsync(cancellationToken);
        return new IntegrationHealthResult(system.LastHealthCheckSucceeded == true, system.LastHealthCheckMessage ?? "Unknown", checkedAt);
    }
}
