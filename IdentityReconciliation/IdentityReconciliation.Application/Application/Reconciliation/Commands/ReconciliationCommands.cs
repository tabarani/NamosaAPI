using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using MediatR;

namespace IdentityReconciliation.Application.Reconciliation.Commands;

public sealed record RunReconciliationCommand : IRequest<ReconciliationReport>;
public sealed record AutoMatchCommand : IRequest<AutoMatchResult>;
public sealed record ResolveConflictCommand(Guid MappingId, bool Link) : IRequest<bool>;
public sealed record LinkUsersCommand(int? MoodleUserId, int? GibbonUserId, string? Email = null) : IRequest<bool>;
public sealed record IgnoreMatchCommand(Guid MappingId) : IRequest;
