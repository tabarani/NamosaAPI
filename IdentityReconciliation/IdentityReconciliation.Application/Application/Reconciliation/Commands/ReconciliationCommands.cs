using IdentityReconciliation.Application.DTOs;
using MediatR;

namespace IdentityReconciliation.Application.Reconciliation.Commands;

public sealed record RunReconciliationCommand : IRequest<ReconciliationReport>;
public sealed record AutoMatchCommand : IRequest<AutoMatchResult>;
public sealed record ResolveConflictCommand(int MappingId, bool Link) : IRequest;
public sealed record LinkUsersCommand(int GibbonUserId, int MoodleUserId, int? IdpUserId = null) : IRequest;
public sealed record IgnoreMatchCommand(int GibbonUserId, int MoodleUserId) : IRequest;
