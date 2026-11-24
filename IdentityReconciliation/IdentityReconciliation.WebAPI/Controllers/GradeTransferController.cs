using IdentityReconciliation.Application.Interfaces;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace IdentityReconciliation.WebAPI.Controllers;

[ApiController]
[Route("api/grade-transfer")]
[Authorize(Roles = "Admin")]
public sealed class GradeTransferController : ControllerBase
{
    private readonly IGradeTransferService _service;
    public GradeTransferController(IGradeTransferService service) => _service = service;

    [HttpGet("courses/{moodleCourseId:int}/status")]
    public async Task<IActionResult> Status(int moodleCourseId, CancellationToken cancellationToken) =>
        Ok((await _service.PreviewAsync(moodleCourseId, cancellationToken)).SingleOrDefault());

    [HttpPost("courses/{moodleCourseId:int}/run")]
    public async Task<IActionResult> RunCourse(int moodleCourseId, CancellationToken cancellationToken) =>
        Ok(await _service.RunAsync(moodleCourseId, true, User.Identity?.Name, cancellationToken));

    [HttpPost("run-scheduled")]
    public async Task<IActionResult> RunScheduled(CancellationToken cancellationToken) =>
        Ok(await _service.RunAsync(null, false, User.Identity?.Name ?? "scheduler", cancellationToken));
}
