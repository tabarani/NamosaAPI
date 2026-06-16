using IdentityReconciliation.Application.Interfaces;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.RazorPages;

namespace IdentityReconciliation.WebAPI.Pages.Admin
{
    [Authorize(Roles = "Admin")]
    public class DashboardModel : PageModel
    {
        private readonly IReconciliationService _reconciliationService;
        private readonly IUserMapRepository _userMapRepository;
        private readonly ILogger<DashboardModel> _logger;

        public DashboardModel(
            IReconciliationService reconciliationService,
            IUserMapRepository userMapRepository,
            ILogger<DashboardModel> logger)
        {
            _reconciliationService = reconciliationService;
            _userMapRepository = userMapRepository;
            _logger = logger;
        }

        [BindProperty]
        public DashboardStats Stats { get; set; } = new();

        [BindProperty]
        public List<PendingMatchDto> PendingMatches { get; set; } = new();

        [BindProperty]
        public List<ConflictDetail> Conflicts { get; set; } = new();

        [BindProperty]
        public List<SyncLogDto> SyncLogs { get; set; } = new();

        [TempData]
        public string? SuccessMessage { get; set; }

        [TempData]
        public string? ErrorMessage { get; set; }

        public record DashboardStats(
            int LinkedCount,
            int PendingCount,
            int ConflictCount,
            int UnmatchedCount
        );

        public async Task<IActionResult> OnGetAsync()
        {
            await LoadDashboardDataAsync();
            return Page();
        }

        public async Task<IActionResult> OnPostRunAutoMatchAsync()
        {
            try
            {
                var matchedCount = await _reconciliationService.AutoMatchAsync();
                SuccessMessage = $"Auto-match completed successfully. {matchedCount} records were automatically linked.";
                _logger.LogInformation("Auto-match executed by admin. Matched: {Count}", matchedCount);
            }
            catch (Exception ex)
            {
                ErrorMessage = $"Auto-match failed: {ex.Message}";
                _logger.LogError(ex, "Auto-match failed");
            }

            return RedirectToPage();
        }

        public async Task<IActionResult> OnPostResolveConflictAsync(Guid userMapId, bool link)
        {
            try
            {
                var result = await _reconciliationService.ResolveConflictAsync(userMapId, link);
                
                if (result && link)
                {
                    SuccessMessage = "Conflict resolved successfully. Users have been linked.";
                }
                else if (!link)
                {
                    SuccessMessage = "Conflict resolution declined. The match has been ignored.";
                }
                else
                {
                    ErrorMessage = "Failed to resolve conflict. Record not found.";
                }

                _logger.LogInformation("Conflict resolution by admin. UserMapId: {Id}, Link: {Link}", userMapId, link);
            }
            catch (Exception ex)
            {
                ErrorMessage = $"Error resolving conflict: {ex.Message}";
                _logger.LogError(ex, "Conflict resolution failed for UserMapId: {Id}", userMapId);
            }

            return RedirectToPage();
        }

        public async Task<IActionResult> OnPostLinkUsersAsync(int? moodleId, int? gibbonId, string? email)
        {
            try
            {
                var result = await _reconciliationService.LinkUsersAsync(moodleId, gibbonId, email);

                if (result)
                {
                    SuccessMessage = "Users linked successfully.";
                }
                else
                {
                    ErrorMessage = "Failed to link users. Please check the provided IDs.";
                }

                _logger.LogInformation("Manual link by admin. MoodleId: {MoodleId}, GibbonId: {GibbonId}", moodleId, gibbonId);
            }
            catch (Exception ex)
            {
                ErrorMessage = $"Error linking users: {ex.Message}";
                _logger.LogError(ex, "Manual link failed");
            }

            return RedirectToPage();
        }

        public async Task<IActionResult> OnPostIgnoreMatchAsync(Guid userMapId)
        {
            try
            {
                await _reconciliationService.IgnoreMatchAsync(userMapId);
                SuccessMessage = "Match has been ignored.";
                _logger.LogInformation("Match ignored by admin. UserMapId: {Id}", userMapId);
            }
            catch (Exception ex)
            {
                ErrorMessage = $"Error ignoring match: {ex.Message}";
                _logger.LogError(ex, "Ignore match failed for UserMapId: {Id}", userMapId);
            }

            return RedirectToPage();
        }

        private async Task LoadDashboardDataAsync()
        {
            try
            {
                var statsDto = await _reconciliationService.GetDashboardStatsAsync();
                Stats = new DashboardStats(
                    statsDto.LinkedCount,
                    statsDto.PendingCount,
                    statsDto.ConflictCount,
                    statsDto.UnmatchedCount
                );

                PendingMatches = await _reconciliationService.GetPendingMatchesAsync();
                Conflicts = await _reconciliationService.GetConflictsAsync();
                SyncLogs = await _reconciliationService.GetSyncLogsAsync();
            }
            catch (Exception ex)
            {
                ErrorMessage = $"Error loading dashboard data: {ex.Message}";
                _logger.LogError(ex, "Failed to load dashboard data");
            }
        }
    }
}
