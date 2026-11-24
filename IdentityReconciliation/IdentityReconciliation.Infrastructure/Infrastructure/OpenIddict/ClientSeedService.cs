using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Interfaces;
using IdentityReconciliation.Infrastructure.Data;
using IdentityReconciliation.Infrastructure.Identity;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Configuration;
using OpenIddict.Abstractions;
using static OpenIddict.Abstractions.OpenIddictConstants;

namespace IdentityReconciliation.Infrastructure.OpenIddict
{
    /// <summary>
    /// Hosted service that seeds the OpenIddict application store with
    /// the Gibbon and Moodle OAuth2 clients, and creates IdentityUser
    /// records from existing UserMap entries.
    /// 
    /// Runs once on application startup.
    /// </summary>
    public class ClientSeedService : IHostedService
    {
        private readonly IServiceProvider _serviceProvider;
        private readonly ILogger<ClientSeedService> _logger;
        private readonly IConfiguration _configuration;

        public ClientSeedService(IServiceProvider serviceProvider, ILogger<ClientSeedService> logger, IConfiguration configuration)
        {
            _serviceProvider = serviceProvider;
            _logger = logger;
            _configuration = configuration;
        }

        public async Task StartAsync(CancellationToken cancellationToken)
        {
            using var scope = _serviceProvider.CreateScope();

            var context = scope.ServiceProvider.GetRequiredService<AppDbContext>();
            await context.Database.EnsureCreatedAsync(cancellationToken);
            var schema = scope.ServiceProvider.GetRequiredService<ControlCenterSchemaInitializer>();
            await schema.EnsureAsync(cancellationToken);

            await SeedClientsAsync(scope, cancellationToken);
            await SeedAdminUserAsync(scope);
        }

        /// <summary>
        /// Registers Gibbon and Moodle as OAuth2 clients in OpenIddict.
        /// </summary>
        private async Task SeedClientsAsync(IServiceScope scope, CancellationToken ct)
        {
            var manager = scope.ServiceProvider.GetRequiredService<IOpenIddictApplicationManager>();

            var gibbonSecret = _configuration["Bootstrap:GibbonClientSecret"];
            var gibbonRedirect = _configuration["Bootstrap:GibbonRedirectUri"];
            var gibbonLogout = _configuration["Bootstrap:GibbonPostLogoutRedirectUri"];
            var moodleSecret = _configuration["Bootstrap:MoodleClientSecret"];
            var moodleRedirect = _configuration["Bootstrap:MoodleRedirectUri"];
            var moodleLogout = _configuration["Bootstrap:MoodlePostLogoutRedirectUri"];

            // ── Gibbon Client ──
            if (!string.IsNullOrWhiteSpace(gibbonSecret) &&
                Uri.TryCreate(gibbonRedirect, UriKind.Absolute, out var gibbonRedirectUri) &&
                Uri.TryCreate(gibbonLogout, UriKind.Absolute, out var gibbonLogoutUri) &&
                await manager.FindByClientIdAsync("gibbon-sso", ct) == null)
            {
                var descriptor = new OpenIddictApplicationDescriptor
                {
                    ClientId = "gibbon-sso",
                    ClientSecret = gibbonSecret,
                    DisplayName = "Gibbon SIS",
                    ClientType = ClientTypes.Confidential,
                    ConsentType = ConsentTypes.Implicit, // Auto-approve (no consent screen)
                    RedirectUris = { gibbonRedirectUri },
                    PostLogoutRedirectUris = { gibbonLogoutUri },
                    Permissions =
                    {
                        Permissions.Endpoints.Authorization,
                        Permissions.Endpoints.Token,
                        Permissions.Endpoints.EndSession,

                        Permissions.GrantTypes.AuthorizationCode,
                        Permissions.GrantTypes.RefreshToken,

                        Permissions.ResponseTypes.Code,

                        Permissions.Scopes.Email,
                        Permissions.Scopes.Profile,
                        Permissions.Scopes.Roles,

                        // Custom scopes for cross-system IDs
                        Permissions.Prefixes.Scope + "gibbon_id",
                        Permissions.Prefixes.Scope + "moodle_id",
                    },
                    Requirements =
                    {
                        Requirements.Features.ProofKeyForCodeExchange
                    }
                };

                await manager.CreateAsync(descriptor, ct);
                _logger.LogInformation("Registered OpenIddict client: gibbon-sso");
            }

            // ── Moodle Client ──
            if (!string.IsNullOrWhiteSpace(moodleSecret) &&
                Uri.TryCreate(moodleRedirect, UriKind.Absolute, out var moodleRedirectUri) &&
                Uri.TryCreate(moodleLogout, UriKind.Absolute, out var moodleLogoutUri) &&
                await manager.FindByClientIdAsync("moodle-sso", ct) == null)
            {
                var descriptor = new OpenIddictApplicationDescriptor
                {
                    ClientId = "moodle-sso",
                    ClientSecret = moodleSecret,
                    DisplayName = "Moodle LMS",
                    ClientType = ClientTypes.Confidential,
                    ConsentType = ConsentTypes.Implicit,
                    RedirectUris = { moodleRedirectUri },
                    PostLogoutRedirectUris = { moodleLogoutUri },
                    Permissions =
                    {
                        Permissions.Endpoints.Authorization,
                        Permissions.Endpoints.Token,
                        Permissions.Endpoints.EndSession,

                        Permissions.GrantTypes.AuthorizationCode,
                        Permissions.GrantTypes.RefreshToken,

                        Permissions.ResponseTypes.Code,

                        Permissions.Scopes.Email,
                        Permissions.Scopes.Profile,
                        Permissions.Scopes.Roles,

                        Permissions.Prefixes.Scope + "moodle_id",
                    }
                };

                await manager.CreateAsync(descriptor, ct);
                _logger.LogInformation("Registered OpenIddict client: moodle-sso");
            }

            // ── Register custom scopes ──
            var scopeManager = scope.ServiceProvider.GetRequiredService<IOpenIddictScopeManager>();

            if (await scopeManager.FindByNameAsync("gibbon_id", ct) == null)
            {
                await scopeManager.CreateAsync(new OpenIddictScopeDescriptor
                {
                    Name = "gibbon_id",
                    DisplayName = "Gibbon Person ID",
                    Description = "Access your Gibbon SIS person identifier"
                }, ct);
            }

            if (await scopeManager.FindByNameAsync("moodle_id", ct) == null)
            {
                await scopeManager.CreateAsync(new OpenIddictScopeDescriptor
                {
                    Name = "moodle_id",
                    DisplayName = "Moodle User ID",
                    Description = "Access your Moodle LMS user identifier"
                }, ct);
            }
        }

        /// <summary>
        /// Creates a default admin user for initial IdP access.
        /// </summary>
        private async Task SeedAdminUserAsync(IServiceScope scope)
        {
            var userManager = scope.ServiceProvider.GetRequiredService<UserManager<ApplicationUser>>();
            var roleManager = scope.ServiceProvider.GetRequiredService<RoleManager<IdentityRole>>();

            // Ensure Admin role exists
            if (!await roleManager.RoleExistsAsync("Admin"))
            {
                await roleManager.CreateAsync(new IdentityRole("Admin"));
                _logger.LogInformation("Created 'Admin' role.");
            }

            var adminEmail = _configuration["Bootstrap:AdminEmail"];
            var adminPassword = _configuration["Bootstrap:AdminPassword"];
            if (string.IsNullOrWhiteSpace(adminEmail) || string.IsNullOrWhiteSpace(adminPassword))
            {
                _logger.LogInformation("Bootstrap administrator was not configured; no administrator account was seeded.");
                return;
            }

            if (await userManager.FindByEmailAsync(adminEmail) == null)
            {
                var admin = new ApplicationUser
                {
                    UserName = adminEmail,
                    Email = adminEmail,
                    EmailConfirmed = true,
                    MustChangePassword = true
                };

                var result = await userManager.CreateAsync(admin, adminPassword);
                if (result.Succeeded)
                {
                    await userManager.AddToRoleAsync(admin, "Admin");
                    _logger.LogInformation("Created admin user: {Email}", adminEmail);
                }
                else
                {
                    _logger.LogWarning("Failed to create admin user: {Errors}",
                        string.Join(", ", result.Errors.Select(e => e.Description)));
                }
            }
        }

        public Task StopAsync(CancellationToken cancellationToken) => Task.CompletedTask;
    }
}
