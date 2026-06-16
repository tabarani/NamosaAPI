using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.RazorPages;
using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Application.DTOs;
using System.Threading.Tasks;
using System.Collections.Generic;
using System.Linq;

namespace IdentityReconciliation.WebAPI.Pages.Admin
{
    [Authorize(Roles = "Admin")]
    public class DashboardModel : PageModel
    {
        private readonly IReconciliationService _reconciliationService;
        private readonly IUserMapRepository _userMapRepository;

        public DashboardModel(IReconciliationService reconciliationService, IUserMapRepository userMapRepository)
        {
            _reconciliationService = reconciliationService;
            _userMapRepository = userMapRepository;
        }

        [BindProperty]
        public DashboardStatsDto Stats { get; set; }

        [BindProperty]
        public List<PendingMatchDto> PendingMatches { get; set; }

        [BindProperty]
        public List<ConflictDto> Conflicts { get; set; }

        [BindProperty]
        public List<SyncLogDto> SyncLogs { get; set; }

        [TempData]
        public string StatusMessage { get; set; }

        public async Task<IActionResult> OnGetAsync()
        {
            Stats = await _reconciliationService.GetDashboardStatsAsync();
            PendingMatches = await _reconciliationService.GetPendingMatchesAsync();
            Conflicts = await _reconciliationService.GetConflictsAsync();
            SyncLogs = await _reconciliationService.GetSyncLogsAsync(50); // Last 50 logs

            return Page();
        }

        public async Task<IActionResult> OnPostRunAutoMatchAsync()
        {
            try
            {
                var result = await _reconciliationService.AutoMatchAsync();
                StatusMessage = $"Auto-match completed. Linked: {result.LinkedCount}, Ignored: {result.IgnoredCount}.";
            }
            catch (System.Exception ex)
            {
                StatusMessage = $"Error during auto-match: {ex.Message}";
            }

            return RedirectToPage();
        }

        public async Task<IActionResult> OnPostResolveConflictAsync(int mappingId, string resolution)
        {
            try
            {
                if (resolution == "link")
                {
                    await _reconciliationService.ResolveConflictAsync(mappingId, true);
                    StatusMessage = "Conflict resolved: Users linked successfully.";
                }
                else if (resolution == "ignore")
                {
                    await _reconciliationService.ResolveConflictAsync(mappingId, false);
                    StatusMessage = "Conflict resolved: Match ignored.";
                }
            }
            catch (System.Exception ex)
            {
                StatusMessage = $"Error resolving conflict: {ex.Message}";
            }

            return RedirectToPage();
        }

        public async Task<IActionResult> OnPostLinkUsersAsync(int gibbonUserId, int moodleUserId, int? idpUserId = null)
        {
            try
            {
                await _reconciliationService.LinkUsersAsync(gibbonUserId, moodleUserId, idpUserId);
                StatusMessage = "Users linked successfully.";
            }
            catch (System.Exception ex)
            {
                StatusMessage = $"Error linking users: {ex.Message}";
            }

            return RedirectToPage();
        }

        public async Task<IActionResult> OnPostIgnoreMatchAsync(int gibbonUserId, int moodleUserId)
        {
            try
            {
                await _reconciliationService.IgnoreMatchAsync(gibbonUserId, moodleUserId);
                StatusMessage = "Match ignored successfully.";
            }
            catch (System.Exception ex)
            {
                StatusMessage = $"Error ignoring match: {ex.Message}";
            }

            return RedirectToPage();
        }
    }
}
