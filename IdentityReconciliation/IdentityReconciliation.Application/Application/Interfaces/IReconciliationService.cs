using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Domain.Entities;

namespace IdentityReconciliation.Application.Interfaces
{
    public interface IReconciliationService
    {
        Task<ReconciliationReport> ReconcileAsync();
        Task<DashboardStatsDto> GetDashboardStatsAsync();
        Task<List<PendingMatchDto>> GetPendingMatchesAsync();
        Task<List<ConflictDto>> GetConflictsAsync();
        Task<List<SyncLogDto>> GetSyncLogsAsync(int count = 50);
        Task<AutoMatchResult> AutoMatchAsync();
        Task ResolveConflictAsync(int mappingId, bool link);
        Task LinkUsersAsync(int gibbonUserId, int moodleUserId, int? idpUserId = null);
        Task IgnoreMatchAsync(int gibbonUserId, int moodleUserId);
    }

    public record DashboardStatsDto(
        int LinkedCount,
        int PendingCount,
        int ConflictCount,
        int UnmatchedCount
    );

    public record PendingMatchDto(
        Guid UserMapId,
        int? GibbonUserId,
        string? GibbonName,
        string? GibbonEmail,
        int? MoodleUserId,
        string? MoodleName,
        string? MoodleEmail,
        string? IdPUsername,
        int EmailScore
    );

    public record ConflictDto(
        Guid MappingId,
        string UserName,
        string GibbonEmail,
        string MoodleEmail,
        string IssueType,
        string Description
    );

    public record SyncLogDto(
        Guid Id,
        string Action,
        string Details,
        string PerformedBy,
        DateTime Timestamp
    );

    public record AutoMatchResult(
        int LinkedCount,
        int IgnoredCount
    );
}
