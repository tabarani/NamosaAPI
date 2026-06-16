using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Domain.Entities;

namespace IdentityReconciliation.Application.Interfaces
{
    public interface IReconciliationService
    {
        Task<ReconciliationReport> ReconcileAsync();
        Task<DashboardStatsDto> GetDashboardStatsAsync();
        Task<List<PendingMatchDto>> GetPendingMatchesAsync();
        Task<List<ConflictDetail>> GetConflictsAsync();
        Task<List<SyncLogDto>> GetSyncLogsAsync();
        Task<int> AutoMatchAsync();
        Task<bool> ResolveConflictAsync(Guid userMapId, bool link);
        Task<bool> LinkUsersAsync(int? moodleId, int? gibbonId, string? email);
        Task IgnoreMatchAsync(Guid userMapId);
    }

    public record DashboardStatsDto(
        int LinkedCount,
        int PendingCount,
        int ConflictCount,
        int UnmatchedCount
    );

    public record PendingMatchDto(
        Guid UserMapId,
        int? GibbonId,
        string? GibbonName,
        string? GibbonEmail,
        int? MoodleId,
        string? MoodleName,
        string? MoodleEmail,
        string? IdPUsername,
        int MatchScore
    );

    public record SyncLogDto(
        Guid Id,
        string Action,
        string Details,
        DateTime CreatedAt
    );
}
