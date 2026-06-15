using Dapper;
using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using Microsoft.Extensions.Configuration;
using MySqlConnector;

namespace IdentityReconciliation.Infrastructure.Repositories
{
    public class GibbonPersonRepository : Application.Interfaces.IGibbonPersonRepository
    {
        private readonly string _connectionString;

        public GibbonPersonRepository(IConfiguration configuration)
        {
            _connectionString = configuration.GetConnectionString("GibbonDb") 
                ?? throw new InvalidOperationException("ConnectionStrings:GibbonDb configuration is required.");
        }

        public async Task<IEnumerable<GibbonPersonDto>> GetAllActivePersonsAsync()
        {
            const string sql = @"
                SELECT 
                    gibbonPersonID AS GibbonPersonId,
                    email AS Email,
                    username AS Username,
                    officialName AS OfficialName
                FROM gibbonPerson
                WHERE status = 'Full'";

            await using var connection = new MySqlConnection(_connectionString);
            await connection.OpenAsync();

            var persons = await connection.QueryAsync<GibbonPersonDto>(sql);
            return persons;
        }
    }
}
