using IdentityReconciliation.Application.DTOs;

namespace IdentityReconciliation.Application.Interfaces
{
    public interface IMoodleApiClient
    {
        Task<List<MoodleUserDto>> GetAllUsersAsync();
        Task UpdateUserAuthMethod(int userId, string authMethod = "oidc");
    }

    public interface IGibbonPersonRepository
    {
        Task<IEnumerable<GibbonPersonDto>> GetAllActivePersonsAsync();
    }
}
