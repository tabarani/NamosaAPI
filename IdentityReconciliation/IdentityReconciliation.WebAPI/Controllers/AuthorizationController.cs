using System.Collections.Immutable;
using System.Security.Claims;
using IdentityReconciliation.Infrastructure.OpenIddict;
using Microsoft.AspNetCore;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Mvc;
using OpenIddict.Abstractions;
using OpenIddict.Server.AspNetCore;
using static OpenIddict.Abstractions.OpenIddictConstants;

namespace IdentityReconciliation.WebAPI.Controllers
{
    /// <summary>
    /// Handles the OpenID Connect authorization and token endpoints.
    /// This is the heart of the Identity Provider — without this controller,
    /// no OAuth2/OIDC flow can function.
    /// </summary>
    [ApiController]
    public class AuthorizationController : ControllerBase
    {
        private readonly SignInManager<IdentityUser> _signInManager;
        private readonly UserManager<IdentityUser> _userManager;
        private readonly CustomProfileService _profileService;
        private readonly ILogger<AuthorizationController> _logger;

        public AuthorizationController(
            SignInManager<IdentityUser> signInManager,
            UserManager<IdentityUser> userManager,
            CustomProfileService profileService,
            ILogger<AuthorizationController> logger)
        {
            _signInManager = signInManager;
            _userManager = userManager;
            _profileService = profileService;
            _logger = logger;
        }

        /// <summary>
        /// GET /connect/authorize — Authorization endpoint.
        /// If the user is already authenticated, auto-approves and redirects back with an authorization code.
        /// If not authenticated, redirects to the login page.
        /// </summary>
        [HttpGet("~/connect/authorize")]
        [HttpPost("~/connect/authorize")]
        public async Task<IActionResult> Authorize()
        {
            var request = HttpContext.GetOpenIddictServerRequest()
                ?? throw new InvalidOperationException("The OpenID Connect request cannot be retrieved.");

            // Try to retrieve the user principal stored in the authentication cookie.
            var result = await HttpContext.AuthenticateAsync(IdentityConstants.ApplicationScheme);

            // If the user is not authenticated, challenge (redirect to login page).
            if (!result.Succeeded || result.Principal == null)
            {
                _logger.LogInformation("User not authenticated, redirecting to login page.");

                // Build the return URL so the login page can redirect back here after login.
                return Challenge(
                    authenticationSchemes: IdentityConstants.ApplicationScheme,
                    properties: new AuthenticationProperties
                    {
                        RedirectUri = Request.PathBase + Request.Path + QueryString.Create(
                            Request.HasFormContentType ? Request.Form.ToList() : Request.Query.ToList())
                    });
            }

            _logger.LogInformation("User {User} authenticated, processing authorization request.",
                result.Principal.Identity?.Name);

            // Create the claims principal for the token.
            var userId = result.Principal.FindFirstValue(ClaimTypes.NameIdentifier)
                ?? throw new InvalidOperationException("User ID claim not found.");

            var user = await _userManager.FindByIdAsync(userId)
                ?? throw new InvalidOperationException("User not found in identity store.");

            var identity = new ClaimsIdentity(
                authenticationType: OpenIddictServerAspNetCoreDefaults.AuthenticationScheme,
                nameType: Claims.Name,
                roleType: Claims.Role);

            // Add standard claims.
            identity.SetClaim(Claims.Subject, userId)
                    .SetClaim(Claims.Name, user.UserName)
                    .SetClaim(Claims.Email, user.Email);

            // Add roles.
            var roles = await _userManager.GetRolesAsync(user);
            identity.SetClaims(Claims.Role, roles.ToImmutableArray());

            var principal = new ClaimsPrincipal(identity);

            // Set the scopes granted to the client.
            principal.SetScopes(request.GetScopes());

            // Set the resources the token can access (if needed).
            principal.SetDestinations(GetDestinations);

            // Enrich with custom claims (gibbon_id, moodle_id) from UserMap.
            await _profileService.EnrichTokenAsync(principal);

            // Ensure custom claims are sent to the right destinations.
            foreach (var claim in principal.Claims)
            {
                if (claim.Type is "gibbon_id" or "moodle_id")
                {
                    claim.SetDestinations(Destinations.AccessToken, Destinations.IdentityToken);
                }
            }

            _logger.LogInformation("Issuing authorization code for user {User} with scopes: {Scopes}",
                user.UserName, string.Join(", ", request.GetScopes()));

            // Sign in with OpenIddict — this generates the authorization code and redirects.
            return SignIn(principal, OpenIddictServerAspNetCoreDefaults.AuthenticationScheme);
        }

        /// <summary>
        /// POST /connect/token — Token endpoint.
        /// Exchanges an authorization code for access + ID tokens.
        /// </summary>
        [HttpPost("~/connect/token")]
        [Produces("application/json")]
        public async Task<IActionResult> Exchange()
        {
            var request = HttpContext.GetOpenIddictServerRequest()
                ?? throw new InvalidOperationException("The OpenID Connect request cannot be retrieved.");

            if (request.IsAuthorizationCodeGrantType() || request.IsRefreshTokenGrantType())
            {
                // Retrieve the claims principal stored in the authorization code/refresh token.
                var result = await HttpContext.AuthenticateAsync(OpenIddictServerAspNetCoreDefaults.AuthenticationScheme);

                if (!result.Succeeded || result.Principal == null)
                {
                    _logger.LogWarning("Token exchange failed: invalid or expired authorization code.");

                    return Forbid(
                        authenticationSchemes: OpenIddictServerAspNetCoreDefaults.AuthenticationScheme,
                        properties: new AuthenticationProperties(new Dictionary<string, string?>
                        {
                            [OpenIddictServerAspNetCoreConstants.Properties.Error] = Errors.InvalidGrant,
                            [OpenIddictServerAspNetCoreConstants.Properties.ErrorDescription] =
                                "The authorization code or refresh token is no longer valid."
                        }));
                }

                var userId = result.Principal.GetClaim(Claims.Subject);
                if (string.IsNullOrEmpty(userId))
                {
                    return Forbid(
                        authenticationSchemes: OpenIddictServerAspNetCoreDefaults.AuthenticationScheme,
                        properties: new AuthenticationProperties(new Dictionary<string, string?>
                        {
                            [OpenIddictServerAspNetCoreConstants.Properties.Error] = Errors.InvalidGrant,
                            [OpenIddictServerAspNetCoreConstants.Properties.ErrorDescription] =
                                "The token subject is missing."
                        }));
                }

                var user = await _userManager.FindByIdAsync(userId);
                if (user == null)
                {
                    return Forbid(
                        authenticationSchemes: OpenIddictServerAspNetCoreDefaults.AuthenticationScheme,
                        properties: new AuthenticationProperties(new Dictionary<string, string?>
                        {
                            [OpenIddictServerAspNetCoreConstants.Properties.Error] = Errors.InvalidGrant,
                            [OpenIddictServerAspNetCoreConstants.Properties.ErrorDescription] =
                                "The user associated with this token no longer exists."
                        }));
                }

                // Re-enrich with custom claims for the actual tokens.
                await _profileService.EnrichTokenAsync(result.Principal);

                // Ensure custom claims have correct destinations.
                foreach (var claim in result.Principal.Claims)
                {
                    if (claim.Type is "gibbon_id" or "moodle_id")
                    {
                        claim.SetDestinations(Destinations.AccessToken, Destinations.IdentityToken);
                    }
                }

                _logger.LogInformation("Token issued for user {User}.", user.UserName);

                return SignIn(result.Principal, OpenIddictServerAspNetCoreDefaults.AuthenticationScheme);
            }

            _logger.LogWarning("Unsupported grant type: {GrantType}", request.GrantType);

            throw new InvalidOperationException("The specified grant type is not supported.");
        }

        /// <summary>
        /// GET /connect/logout — End-session endpoint.
        /// Logs the user out of the IdP and redirects back to the client.
        /// </summary>
        [HttpGet("~/connect/logout")]
        [HttpPost("~/connect/logout")]
        public async Task<IActionResult> Logout()
        {
            var request = HttpContext.GetOpenIddictServerRequest();

            // Sign out of the Identity cookie.
            await _signInManager.SignOutAsync();

            _logger.LogInformation("User signed out from Identity Provider.");

            // If a post_logout_redirect_uri was provided, redirect there.
            // Otherwise OpenIddict will handle the redirect automatically.
            return SignOut(
                authenticationSchemes: OpenIddictServerAspNetCoreDefaults.AuthenticationScheme,
                properties: new AuthenticationProperties
                {
                    RedirectUri = "/"
                });
        }

        /// <summary>
        /// GET /connect/userinfo — UserInfo endpoint (optional but good practice).
        /// Returns claims about the authenticated user.
        /// </summary>
        [HttpGet("~/connect/userinfo")]
        [HttpPost("~/connect/userinfo")]
        public async Task<IActionResult> Userinfo()
        {
            var result = await HttpContext.AuthenticateAsync(OpenIddictServerAspNetCoreDefaults.AuthenticationScheme);
            if (!result.Succeeded || result.Principal == null)
            {
                return Challenge(
                    authenticationSchemes: OpenIddictServerAspNetCoreDefaults.AuthenticationScheme,
                    properties: new AuthenticationProperties(new Dictionary<string, string?>
                    {
                        [OpenIddictServerAspNetCoreConstants.Properties.Error] = Errors.InvalidToken,
                        [OpenIddictServerAspNetCoreConstants.Properties.ErrorDescription] =
                            "The specified access token is no longer valid."
                    }));
            }

            var claims = new Dictionary<string, object>(StringComparer.Ordinal)
            {
                [Claims.Subject] = result.Principal.GetClaim(Claims.Subject) ?? string.Empty
            };

            if (result.Principal.HasScope(Scopes.Profile))
            {
                claims[Claims.Name] = result.Principal.GetClaim(Claims.Name) ?? string.Empty;
            }

            if (result.Principal.HasScope(Scopes.Email))
            {
                claims[Claims.Email] = result.Principal.GetClaim(Claims.Email) ?? string.Empty;
            }

            // Include custom claims.
            var gibbonId = result.Principal.GetClaim("gibbon_id");
            if (!string.IsNullOrEmpty(gibbonId))
            {
                claims["gibbon_id"] = gibbonId;
            }

            var moodleId = result.Principal.GetClaim("moodle_id");
            if (!string.IsNullOrEmpty(moodleId))
            {
                claims["moodle_id"] = moodleId;
            }

            return Ok(claims);
        }

        /// <summary>
        /// Determines which destinations (access_token, id_token) each claim should be sent to.
        /// </summary>
        private static IEnumerable<string> GetDestinations(Claim claim)
        {
            // Always include the subject (sub) in both tokens
            if (claim.Type == Claims.Subject)
            {
                yield return Destinations.AccessToken;
                yield return Destinations.IdentityToken;
                yield break;
            }

            switch (claim.Type)
            {
                case Claims.Name or Claims.PreferredUsername:
                    yield return Destinations.AccessToken;
                    if (claim.Subject?.HasScope(Scopes.Profile) == true)
                        yield return Destinations.IdentityToken;
                    yield break;

                case Claims.Email:
                    yield return Destinations.AccessToken;
                    if (claim.Subject?.HasScope(Scopes.Email) == true)
                        yield return Destinations.IdentityToken;
                    yield break;

                case Claims.Role:
                    yield return Destinations.AccessToken;
                    if (claim.Subject?.HasScope(Scopes.Roles) == true)
                        yield return Destinations.IdentityToken;
                    yield break;

                case "gibbon_id" or "moodle_id":
                    yield return Destinations.AccessToken;
                    yield return Destinations.IdentityToken;
                    yield break;

                // Never include the security stamp in tokens.
                case "AspNet.Identity.SecurityStamp":
                    yield break;

                default:
                    yield return Destinations.AccessToken;
                    yield break;
            }
        }
    }
}
