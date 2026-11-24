using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Enums;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.RazorPages;

namespace IdentityReconciliation.WebAPI.Pages.Admin;

[Authorize(Roles = "Admin")]
public class SystemsModel : PageModel
{
    private readonly IIntegrationSystemService _systems;

    public SystemsModel(IIntegrationSystemService systems) => _systems = systems;

    public IReadOnlyList<IntegrationSystem> Items { get; private set; } = Array.Empty<IntegrationSystem>();

    [BindProperty]
    public SystemInput Input { get; set; } = new();

    [TempData]
    public string? Message { get; set; }

    public async Task OnGetAsync() => Items = await _systems.GetAllAsync();

    public async Task<IActionResult> OnPostSaveAsync()
    {
        if (!ModelState.IsValid)
        {
            Items = await _systems.GetAllAsync();
            return Page();
        }

        var system = new IntegrationSystem
        {
            Id = Input.Id == Guid.Empty ? Guid.NewGuid() : Input.Id,
            Name = Input.Name.Trim(),
            Type = Input.Type,
            BaseUrl = Input.BaseUrl.Trim(),
            IsEnabled = Input.IsEnabled,
            ProvisioningMode = Input.ProvisioningMode,
            AllowAccountCreation = Input.AllowAccountCreation,
            AllowFirstLoginClaim = Input.AllowFirstLoginClaim
        };

        var secrets = new Dictionary<string, string?>();
        if (!string.IsNullOrWhiteSpace(Input.ApiToken)) secrets["ApiToken"] = Input.ApiToken;
        if (!string.IsNullOrWhiteSpace(Input.DatabaseConnection)) secrets["DatabaseConnection"] = Input.DatabaseConnection;
        if (!string.IsNullOrWhiteSpace(Input.VerificationSecret)) secrets["VerificationSecret"] = Input.VerificationSecret;

        await _systems.SaveAsync(system, secrets, User.Identity?.Name);
        Message = $"{system.Name} configuration saved.";
        return RedirectToPage();
    }

    public async Task<IActionResult> OnPostHealthAsync(Guid id)
    {
        var result = await _systems.CheckHealthAsync(id);
        Message = result.Succeeded ? $"Connection succeeded: {result.Message}" : $"Connection failed: {result.Message}";
        return RedirectToPage();
    }

    public sealed class SystemInput
    {
        public Guid Id { get; set; }
        public string Name { get; set; } = string.Empty;
        public IntegrationSystemType Type { get; set; }
        public string BaseUrl { get; set; } = string.Empty;
        public bool IsEnabled { get; set; } = true;
        public ProvisioningMode ProvisioningMode { get; set; }
        public bool AllowAccountCreation { get; set; }
        public bool AllowFirstLoginClaim { get; set; }
        public string? ApiToken { get; set; }
        public string? DatabaseConnection { get; set; }
        public string? VerificationSecret { get; set; }
    }
}
