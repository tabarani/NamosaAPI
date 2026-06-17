using IdentityReconciliation.Infrastructure.Clients;
using IdentityReconciliation.Infrastructure.Data;
using IdentityReconciliation.Infrastructure.Repositories;
using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Interfaces;
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
            
            services.AddHttpClient<IMoodleApiClient, MoodleApiClient>(client =>
            {
                client.BaseAddress = new Uri(config["Moodle:BaseUrl"]!);
            });


            return services;
        }
    }
}
