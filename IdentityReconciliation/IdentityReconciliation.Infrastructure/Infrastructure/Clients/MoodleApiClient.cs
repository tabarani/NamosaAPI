using System.Net.Http.Json;
using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using Microsoft.Extensions.Configuration;
using IdentityReconciliation.Domain.Enums;

namespace IdentityReconciliation.Infrastructure.Clients
{
    public class MoodleApiClient : Application.Interfaces.IMoodleApiClient
    {
        private readonly HttpClient _httpClient;
        private readonly IConfiguration _configuration;
        private readonly IIntegrationConnectionResolver _connections;

        public MoodleApiClient(HttpClient httpClient, IConfiguration configuration, IIntegrationConnectionResolver connections)
        {
            _httpClient = httpClient;
            _configuration = configuration;
            _connections = connections;
        }

        public async Task<List<MoodleUserDto>> GetAllUsersAsync()
        {
            var connection = await ResolveConnectionAsync();
            var parameters = new Dictionary<string, string>
            {
                { "wstoken", connection.Token },
                { "wsfunction", "core_user_get_users" },
                { "moodlewsrestformat", "json" },
                { "criteria[0][key]", "id" },
                { "criteria[0][value]", "0" }
            };

            var response = await _httpClient.PostAsync(connection.Endpoint, new FormUrlEncodedContent(parameters));
            response.EnsureSuccessStatusCode();

            var json = await response.Content.ReadAsStringAsync();
            var result = System.Text.Json.JsonSerializer.Deserialize<MoodleUsersResponse>(json, 
                new System.Text.Json.JsonSerializerOptions { PropertyNameCaseInsensitive = true });

            return result?.Users ?? new List<MoodleUserDto>();
        }

        public async Task UpdateUserAuthMethod(int userId, string authMethod = "oidc")
        {
            var connection = await ResolveConnectionAsync();
            var parameters = new Dictionary<string, string>
            {
                { "wstoken", connection.Token },
                { "wsfunction", "core_user_update_users" },
                { "moodlewsrestformat", "json" },
                { "users[0][id]", userId.ToString() },
                { "users[0][auth]", authMethod }
            };

            var response = await _httpClient.PostAsync(connection.Endpoint, new FormUrlEncodedContent(parameters));
            response.EnsureSuccessStatusCode();
        }

        private async Task<(Uri Endpoint, string Token)> ResolveConnectionAsync()
        {
            var configured = await _connections.ResolveAsync(IntegrationSystemType.Moodle);
            var token = configured?.Secrets.GetValueOrDefault("ApiToken") ?? _configuration["Moodle:ApiToken"];
            var baseUrl = configured?.BaseUrl ?? _configuration["Moodle:BaseUrl"];
            if (string.IsNullOrWhiteSpace(token) || string.IsNullOrWhiteSpace(baseUrl))
                throw new InvalidOperationException("Configure an enabled Moodle connection in the Yulpana Control Center.");

            var endpoint = baseUrl.Contains("webservice/rest/server.php", StringComparison.OrdinalIgnoreCase)
                ? baseUrl
                : $"{baseUrl.TrimEnd('/')}/webservice/rest/server.php";
            return (new Uri(endpoint), token);
        }

        private class MoodleUsersResponse
        {
            public List<MoodleUserDto> Users { get; set; } = new();
        }
    }
}
