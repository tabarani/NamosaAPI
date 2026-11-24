using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.RazorPages;

namespace IdentityReconciliation.WebAPI.Pages.Admin;

[Authorize(Roles = "Admin")]
public sealed class GibbonToMoodleModel : PageModel
{
    private readonly IGibbonToMoodleGradeService _transfer;
    private readonly IControlCenterSettings _settings;
    public GibbonToMoodleModel(IGibbonToMoodleGradeService transfer, IControlCenterSettings settings) { _transfer = transfer; _settings = settings; }

    public IReadOnlyList<GibbonToMoodleCourseStatus> Courses { get; private set; } = Array.Empty<GibbonToMoodleCourseStatus>();
    [BindProperty] public string[] SelectedClassIds { get; set; } = Array.Empty<string>();
    [BindProperty] public bool Enabled { get; set; }
    [BindProperty] public string SemesterKey { get; set; } = "semester";
    [TempData] public string? Message { get; set; }
    [TempData] public string? Error { get; set; }

    public async Task OnGetAsync()
    {
        Enabled = bool.TryParse(await _settings.GetAsync("GradeTransfer", "GibbonToMoodleEnabled"), out var enabled) && enabled;
        SemesterKey = await _settings.GetAsync("GradeTransfer", "SemesterKey") ?? "semester";
        Courses = await _transfer.PreviewAsync();
    }

    public async Task<IActionResult> OnPostPolicyAsync()
    {
        if (string.IsNullOrWhiteSpace(SemesterKey)) { Error = "Semester key is required."; return RedirectToPage(); }
        await _settings.SetAsync("GradeTransfer", "GibbonToMoodleEnabled", Enabled.ToString(), false, User.Identity?.Name);
        await _settings.SetAsync("GradeTransfer", "SemesterKey", SemesterKey.Trim(), false, User.Identity?.Name);
        Message = Enabled ? "Gibbon-to-Moodle transfer enabled." : "Gibbon-to-Moodle transfer disabled.";
        return RedirectToPage();
    }

    public async Task<IActionResult> OnPostTransferAsync(bool confirmed)
    {
        if (!confirmed || SelectedClassIds.Length == 0)
        {
            Error = "Select one or more ready courses and confirm the transfer.";
            return RedirectToPage();
        }
        try
        {
            var result = await _transfer.RunAsync(SelectedClassIds, User.Identity?.Name);
            var succeeded = result.Courses.Count(x => x.Status == "Transferred");
            Message = $"Job {result.JobId}: {succeeded} of {result.Courses.Count} selected courses transferred. Review statuses and operation logs.";
        }
        catch (Exception ex) { Error = ex.Message; }
        return RedirectToPage();
    }
}
