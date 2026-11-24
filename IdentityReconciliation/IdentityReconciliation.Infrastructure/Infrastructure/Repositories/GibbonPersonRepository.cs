using Dapper;
using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using Microsoft.Extensions.Configuration;
using MySqlConnector;
using IdentityReconciliation.Domain.Enums;

namespace IdentityReconciliation.Infrastructure.Repositories
{
    public class GibbonPersonRepository : Application.Interfaces.IGibbonPersonRepository
    {
        private readonly IConfiguration _configuration;
        private readonly IIntegrationConnectionResolver _connections;

        public GibbonPersonRepository(IConfiguration configuration, IIntegrationConnectionResolver connections)
        {
            _configuration = configuration;
            _connections = connections;
        }

        public async Task<IEnumerable<GibbonPersonDto>> GetAllActivePersonsAsync()
        {
            var configured = await _connections.ResolveAsync(IntegrationSystemType.Gibbon);
            var connectionString = configured?.Secrets.GetValueOrDefault("DatabaseConnection")
                ?? _configuration.GetConnectionString("GibbonDb");
            if (string.IsNullOrWhiteSpace(connectionString))
                throw new InvalidOperationException("Configure an enabled Gibbon connection in the Yulpana Control Center.");
            const string sql = @"
                SELECT 
                    gibbonPersonID AS GibbonPersonId,
                    email AS Email,
                    username AS Username,
                    officialName AS OfficialName
                FROM gibbonPerson
                WHERE status = 'Full'";

            await using var connection = new MySqlConnection(connectionString);
            await connection.OpenAsync();

            var persons = await connection.QueryAsync<GibbonPersonDto>(sql);
            return persons;
        }
    }
}
