using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Interfaces;
using IdentityReconciliation.Infrastructure.Data;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
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

        public ClientSeedService(IServiceProvider serviceProvider, ILogger<ClientSeedService> logger)
        {
            _serviceProvider = serviceProvider;
            _logger = logger;
        }

        public async Task StartAsync(CancellationToken cancellationToken)
        {
            using var scope = _serviceProvider.CreateScope();

            var context = scope.ServiceProvider.GetRequiredService<AppDbContext>();
            await context.Database.EnsureCreatedAsync(cancellationToken);

            await SeedClientsAsync(scope, cancellationToken);
            await SeedAdminUserAsync(scope);
        }

        /// <summary>
        /// Registers Gibbon and Moodle as OAuth2 clients in OpenIddict.
        /// </summary>
        private async Task SeedClientsAsync(IServiceScope scope, CancellationToken ct)
        {
            var manager = scope.ServiceProvider.GetRequiredService<IOpenIddictApplicationManager>();

            // ── Gibbon Client ──
            if (await manager.FindByClientIdAsync("gibbon-sso", ct) == null)
            {
                var descriptor = new OpenIddictApplicationDescriptor
                {
                    ClientId = "gibbon-sso",
                    ClientSecret = "gibbon-sso-secret-change-me",
                    DisplayName = "Gibbon SIS",
                    ClientType = ClientTypes.Confidential,
                    ConsentType = ConsentTypes.Implicit, // Auto-approve (no consent screen)
                    // Add your actual Gibbon URL here:
                    RedirectUris = { new Uri("https://nbs.edu.mr/sis2/index.php?q=/modules/OIDC/callback.php") },
                    PostLogoutRedirectUris = { new Uri("https://nbs.edu.mr/sis2/index.php") },
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
            if (await manager.FindByClientIdAsync("moodle-sso", ct) == null)
            {
                var descriptor = new OpenIddictApplicationDescriptor
                {
                    ClientId = "moodle-sso",
                    ClientSecret = "moodle-sso-secret-change-me",
                    DisplayName = "Moodle LMS",
                    ClientType = ClientTypes.Confidential,
                    ConsentType = ConsentTypes.Implicit,
                    // Add your actual Moodle URL here:
                    RedirectUris = { new Uri("https://nbs.edu.mr/lms2/auth/oidc/") },
                    PostLogoutRedirectUris = { new Uri("https://nbs.edu.mr/lms2/") },
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
            var userManager = scope.ServiceProvider.GetRequiredService<UserManager<IdentityUser>>();
            var roleManager = scope.ServiceProvider.GetRequiredService<RoleManager<IdentityRole>>();

            // Ensure Admin role exists
            if (!await roleManager.RoleExistsAsync("Admin"))
            {
                await roleManager.CreateAsync(new IdentityRole("Admin"));
                _logger.LogInformation("Created 'Admin' role.");
            }

            // Create default admin user
            const string adminEmail = "admin@nbs.edu.mr";
            const string adminPassword = "Admin@2024!"; // CHANGE THIS IN PRODUCTION

            if (await userManager.FindByEmailAsync(adminEmail) == null)
            {
                var admin = new IdentityUser
                {
                    UserName = adminEmail,
                    Email = adminEmail,
                    EmailConfirmed = true
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
