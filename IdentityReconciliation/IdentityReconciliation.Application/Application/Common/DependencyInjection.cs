using System.Reflection;
using MediatR;
using Microsoft.Extensions.DependencyInjection;

namespace IdentityReconciliation.Application.Common;

public static class DependencyInjection
{
    public static IServiceCollection AddApplication(this IServiceCollection services)
    {
        services.AddMediatR(cfg => cfg.RegisterServicesFromAssembly(Assembly.GetExecutingAssembly()));
        services.AddScoped<Interfaces.IReconciliationService, Services.ReconciliationService>();
        return services;
    }
}
