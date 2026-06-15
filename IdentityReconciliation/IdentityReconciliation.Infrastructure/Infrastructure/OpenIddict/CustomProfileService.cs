using System.Security.Claims;
using IdentityReconciliation.Domain.Interfaces;
using OpenIddict.Abstractions;

namespace IdentityReconciliation.Infrastructure.OpenIddict
{
    /// <summary>
    /// Adds custom claims (gibbon_id, moodle_id) to tokens during sign-in
    /// </summary>
    public class CustomProfileService
    {
        private readonly IUserMapRepository _userMapRepository;

        public CustomProfileService(IUserMapRepository userMapRepository)
        {
            _userMapRepository = userMapRepository;
        }

        public async Task EnrichTokenAsync(ClaimsPrincipal principal)
        {
            if (principal == null)
                throw new ArgumentNullException(nameof(principal));

            var subject = principal.FindFirstValue(ClaimTypes.NameIdentifier);

            if (string.IsNullOrEmpty(subject) || !Guid.TryParse(subject, out var identityUserId))
            {
                return;
            }

            var userMap = await _userMapRepository.GetByIdentityUserIdAsync(identityUserId);

            if (userMap != null)
            {
                var identity = principal.Identity as ClaimsIdentity;
                
                if (userMap.GibbonId.HasValue)
                {
                    identity?.AddClaim(new Claim("gibbon_id", userMap.GibbonId.Value.ToString()));
                }

                if (userMap.MoodleId.HasValue)
                {
                    identity?.AddClaim(new Claim("moodle_id", userMap.MoodleId.Value.ToString()));
                }
            }
        }
    }
}
