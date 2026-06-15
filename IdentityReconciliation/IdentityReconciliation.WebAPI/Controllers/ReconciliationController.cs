using IdentityReconciliation.Application.Interfaces;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace IdentityReconciliation.WebAPI.Controllers
{
    [Authorize(Roles = "Admin")]
    [Route("api/admin/reconciliation")]
    [ApiController]
    public class ReconciliationController : ControllerBase
    {
        private readonly IReconciliationService _reconciliationService;

        public ReconciliationController(IReconciliationService reconciliationService)
        {
            _reconciliationService = reconciliationService;
        }

        [HttpPost("run")]
        public async Task<IActionResult> Run()
        {
            var report = await _reconciliationService.ReconcileAsync();
            return Ok(report);
        }

        [HttpGet("status")]
        public IActionResult Status()
        {
            return Ok(new { Message = "Reconciliation service is operational" });
        }
    }
}
