using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Entities;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc.RazorPages;

namespace IdentityReconciliation.WebAPI.Pages.Admin;

[Authorize(Roles = "Admin")]
public class JobsModel : PageModel
{
    private readonly ISyncJobService _jobs;
    public JobsModel(ISyncJobService jobs) => _jobs = jobs;
    public IReadOnlyList<SyncJob> Items { get; private set; } = Array.Empty<SyncJob>();
    public async Task OnGetAsync() => Items = await _jobs.GetRecentAsync();
}
