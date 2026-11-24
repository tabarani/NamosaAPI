using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Application.Reconciliation.Commands;
using IdentityReconciliation.Application.Reconciliation.Queries;
using MediatR;

namespace IdentityReconciliation.Application.Services
{
    public class ReconciliationService : IReconciliationService
    {
        private readonly IMediator _mediator;

        public ReconciliationService(IMediator mediator)
        {
            _mediator = mediator;
        }

        public Task<ReconciliationReport> ReconcileAsync() => _mediator.Send(new RunReconciliationCommand());
        public Task<DashboardStatsDto> GetDashboardStatsAsync() => _mediator.Send(new GetDashboardStatsQuery());
        public Task<List<PendingMatchDto>> GetPendingMatchesAsync() => _mediator.Send(new GetPendingMatchesQuery());
        public Task<List<ConflictDto>> GetConflictsAsync() => _mediator.Send(new GetConflictsQuery());
        public Task<List<SyncLogDto>> GetSyncLogsAsync(int count = 50) => _mediator.Send(new GetSyncLogsQuery(count));
        public Task<AutoMatchResult> AutoMatchAsync() => _mediator.Send(new AutoMatchCommand());
        public Task<bool> ResolveConflictAsync(Guid mappingId, bool link) => _mediator.Send(new ResolveConflictCommand(mappingId, link));
        public Task<bool> LinkUsersAsync(int? moodleUserId, int? gibbonUserId, string? email = null) => _mediator.Send(new LinkUsersCommand(moodleUserId, gibbonUserId, email));
        public Task IgnoreMatchAsync(Guid mappingId) => _mediator.Send(new IgnoreMatchCommand(mappingId));
    }
}
