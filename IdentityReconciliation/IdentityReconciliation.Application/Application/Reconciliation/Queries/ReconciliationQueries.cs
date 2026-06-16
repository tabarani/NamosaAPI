using IdentityReconciliation.Application.Interfaces;
using MediatR;

namespace IdentityReconciliation.Application.Reconciliation.Queries;

public sealed record GetDashboardStatsQuery : IRequest<DashboardStatsDto>;
public sealed record GetPendingMatchesQuery : IRequest<List<PendingMatchDto>>;
public sealed record GetConflictsQuery : IRequest<List<ConflictDto>>;
public sealed record GetSyncLogsQuery(int Count = 50) : IRequest<List<SyncLogDto>>;
