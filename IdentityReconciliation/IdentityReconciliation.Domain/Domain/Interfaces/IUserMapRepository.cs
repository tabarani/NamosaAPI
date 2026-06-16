using IdentityReconciliation.Domain.Entities;

namespace IdentityReconciliation.Domain.Interfaces
{
    public interface IUserMapRepository
    {
        Task<UserMap?> GetByIdAsync(Guid id);
        Task<UserMap?> GetByMoodleIdAsync(int moodleId);
        Task<UserMap?> GetByGibbonIdAsync(int gibbonId);
        Task<UserMap?> GetByEmailAsync(string email);
        Task<UserMap?> GetByIdentityUserIdAsync(Guid identityUserId);
        Task AddAsync(UserMap map);
        Task UpdateAsync(UserMap map);
        Task SaveChangesAsync();
        
        // Dashboard queries
        Task<int> CountByStatusAsync(Domain.Enums.MatchStatus status);
        Task<List<UserMap>> GetPendingMatchesAsync();
        Task<List<UserMap>> GetConflictsAsync();
        Task<List<UserMap>> GetRecentSyncLogsAsync(int count = 50);
    }
}
