using IdentityReconciliation.Application.DTOs;

namespace IdentityReconciliation.Application.Interfaces
{
    public interface IReconciliationService
    {
        Task<ReconciliationReport> ReconcileAsync();
    }
}
