using System.Net.Http.Json;
using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using Microsoft.Extensions.Configuration;

namespace IdentityReconciliation.Infrastructure.Clients
{
    public class MoodleApiClient : Application.Interfaces.IMoodleApiClient
    {
        private readonly HttpClient _httpClient;
        private readonly string _apiToken;

        public MoodleApiClient(HttpClient httpClient, IConfiguration configuration)
        {
            _httpClient = httpClient;
            _apiToken = configuration["Moodle:ApiToken"] 
                ?? throw new InvalidOperationException("Moodle:ApiToken configuration is required.");
        }

        public async Task<List<MoodleUserDto>> GetAllUsersAsync()
        {
            var parameters = new Dictionary<string, string>
            {
                { "wstoken", _apiToken },
                { "wsfunction", "core_user_get_users" },
                { "moodlewsrestformat", "json" },
                { "criteria[0][key]", "id" },
                { "criteria[0][value]", "0" }
            };

            var response = await _httpClient.PostAsync("", new FormUrlEncodedContent(parameters));
            response.EnsureSuccessStatusCode();

            var json = await response.Content.ReadAsStringAsync();
            var result = System.Text.Json.JsonSerializer.Deserialize<MoodleUsersResponse>(json, 
                new System.Text.Json.JsonSerializerOptions { PropertyNameCaseInsensitive = true });

            return result?.Users ?? new List<MoodleUserDto>();
        }

        public async Task UpdateUserAuthMethod(int userId, string authMethod = "oidc")
        {
            var parameters = new Dictionary<string, string>
            {
                { "wstoken", _apiToken },
                { "wsfunction", "core_user_update_users" },
                { "moodlewsrestformat", "json" },
                { "users[0][id]", userId.ToString() },
                { "users[0][auth]", authMethod }
            };

            var response = await _httpClient.PostAsync("", new FormUrlEncodedContent(parameters));
            response.EnsureSuccessStatusCode();
        }

        private class MoodleUsersResponse
        {
            public List<MoodleUserDto> Users { get; set; } = new();
        }
    }
}
