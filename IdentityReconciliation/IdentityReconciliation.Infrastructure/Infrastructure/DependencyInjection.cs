using IdentityReconciliation.Infrastructure.Clients;
using IdentityReconciliation.Infrastructure.Data;
using IdentityReconciliation.Infrastructure.Repositories;
using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Interfaces;
using IdentityReconciliation.Infrastructure.Configuration;
using IdentityReconciliation.Infrastructure.Jobs;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;

namespace Microsoft.Extensions.DependencyInjection
{
    public static class DependencyInjection
    {
        public static IServiceCollection AddInfrastructure(this IServiceCollection services, IConfiguration config)
        {
            services.AddDbContext<AppDbContext>(options =>
            {
                options.UseSqlServer(config.GetConnectionString("DefaultConnection"));
                options.UseOpenIddict();
            });

            services.AddScoped<IUserMapRepository, UserMapRepository>();
            services.AddScoped<IGibbonPersonRepository, GibbonPersonRepository>();
            services.AddScoped<IControlCenterSettings, ControlCenterSettings>();
            services.AddScoped<IntegrationSystemService>();
            services.AddScoped<IIntegrationSystemService>(sp => sp.GetRequiredService<IntegrationSystemService>());
            services.AddScoped<IIntegrationConnectionResolver>(sp => sp.GetRequiredService<IntegrationSystemService>());
            services.AddScoped<ControlCenterSchemaInitializer>();
            services.AddScoped<ISyncJobService, SyncJobService>();
            services.AddDataProtection();
            services.AddHttpClient("IntegrationHealth", client => client.Timeout = TimeSpan.FromSeconds(10));
            
            services.AddHttpClient<IMoodleApiClient, MoodleApiClient>();


            return services;
        }
    }
}
