using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.RazorPages;

namespace IdentityReconciliation.WebAPI.Pages.Admin;

[Authorize(Roles = "Admin")]
public sealed class GradeTransferModel : PageModel
{
    private readonly IGradeTransferService _service;
    public GradeTransferModel(IGradeTransferService service) => _service = service;

    [BindProperty] public PolicyInput Input { get; set; } = new();
    public IReadOnlyList<GradeTransferPreviewItem> Preview { get; private set; } = Array.Empty<GradeTransferPreviewItem>();
    [TempData] public string? Message { get; set; }
    [TempData] public string? Error { get; set; }

    public async Task OnGetAsync(int? courseId)
    {
        var policy = await _service.GetPolicyAsync();
        Input = PolicyInput.From(policy);
        if (courseId.HasValue)
        {
            try { Preview = await _service.PreviewAsync(courseId); }
            catch (Exception ex) { Error = ex.Message; }
        }
    }

    public async Task<IActionResult> OnPostSaveAsync()
    {
        if (Input.WindowStart.HasValue && Input.WindowEnd.HasValue && Input.WindowStart > Input.WindowEnd)
            ModelState.AddModelError(string.Empty, "The transfer window start must be before its end.");
        if (string.IsNullOrWhiteSpace(Input.SemesterKey) || string.IsNullOrWhiteSpace(Input.ActorGibbonPersonId))
            ModelState.AddModelError(string.Empty, "Semester key and Gibbon actor person ID are required.");
        if (!ModelState.IsValid) return Page();
        await _service.SavePolicyAsync(Input.ToPolicy(), User.Identity?.Name);
        Message = "Grade transfer policy saved.";
        return RedirectToPage();
    }

    public async Task<IActionResult> OnPostPreviewAsync(int? courseId)
    {
        Input = PolicyInput.From(await _service.GetPolicyAsync());
        try { Preview = await _service.PreviewAsync(courseId); }
        catch (Exception ex) { Error = ex.Message; return RedirectToPage(); }
        return Page();
    }

    public async Task<IActionResult> OnPostRunAsync(int? courseId, bool confirmed)
    {
        if (!confirmed)
        {
            Error = "Confirm that Moodle course totals are final before transferring them.";
            return RedirectToPage();
        }
        try
        {
            var result = await _service.RunAsync(courseId, true, User.Identity?.Name);
            Message = $"Grade transfer job {result.JobId} finished. Review the per-course status and operation log.";
        }
        catch (Exception ex) { Error = ex.Message; }
        return RedirectToPage(new { courseId });
    }

    public sealed class PolicyInput
    {
        public bool Enabled { get; set; }
        public bool ManualTransferEnabled { get; set; } = true;
        public bool ScheduledTransferEnabled { get; set; }
        public DateTimeOffset? WindowStart { get; set; }
        public DateTimeOffset? WindowEnd { get; set; }
        public bool TransferAsPercentage { get; set; } = true;
        public bool RequireCompleteGrades { get; set; } = true;
        public bool IncludeHiddenGrades { get; set; }
        public bool OverwriteExisting { get; set; } = true;
        public string SemesterKey { get; set; } = "semester";
        public string ColumnName { get; set; } = "Moodle Semester Total";
        public string ActorGibbonPersonId { get; set; } = string.Empty;
        public bool ViewableStudents { get; set; } = true;
        public bool ViewableParents { get; set; } = true;

        public GradeTransferPolicy ToPolicy() => new(Enabled, ManualTransferEnabled, ScheduledTransferEnabled, WindowStart, WindowEnd,
            TransferAsPercentage, RequireCompleteGrades, IncludeHiddenGrades, OverwriteExisting, SemesterKey.Trim(), ColumnName.Trim(),
            ActorGibbonPersonId.Trim(), ViewableStudents, ViewableParents);
        public static PolicyInput From(GradeTransferPolicy p) => new() { Enabled = p.Enabled, ManualTransferEnabled = p.ManualTransferEnabled,
            ScheduledTransferEnabled = p.ScheduledTransferEnabled, WindowStart = p.WindowStart, WindowEnd = p.WindowEnd,
            TransferAsPercentage = p.TransferAsPercentage, RequireCompleteGrades = p.RequireCompleteGrades, IncludeHiddenGrades = p.IncludeHiddenGrades,
            OverwriteExisting = p.OverwriteExisting, SemesterKey = p.SemesterKey, ColumnName = p.ColumnName, ActorGibbonPersonId = p.ActorGibbonPersonId,
            ViewableStudents = p.ViewableStudents, ViewableParents = p.ViewableParents };
    }
}
