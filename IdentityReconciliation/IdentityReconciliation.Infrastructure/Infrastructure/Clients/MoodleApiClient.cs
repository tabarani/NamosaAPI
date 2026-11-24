using System.Net.Http.Json;
using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using Microsoft.Extensions.Configuration;
using IdentityReconciliation.Domain.Enums;
using System.Globalization;
using System.Text.Json;

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

        public async Task<IReadOnlyList<MoodleCourseDto>> GetCoursesAsync(CancellationToken cancellationToken = default)
        {
            using var document = await CallAsync("core_course_get_courses", new(), cancellationToken);
            return document.RootElement.EnumerateArray()
                .Where(x => ReadInt(x, "id") > 1)
                .Select(x => new MoodleCourseDto(ReadInt(x, "id"), ReadString(x, "fullname"), ReadString(x, "shortname"), ReadString(x, "idnumber")))
                .ToList();
        }

        public async Task<IReadOnlyList<MoodleEnrolledUserDto>> GetEnrolledUsersAsync(int courseId, CancellationToken cancellationToken = default)
        {
            using var document = await CallAsync("core_enrol_get_enrolled_users", new() { ["courseid"] = courseId.ToString(CultureInfo.InvariantCulture) }, cancellationToken);
            return document.RootElement.EnumerateArray()
                .Select(x => new MoodleEnrolledUserDto(ReadInt(x, "id"), ReadString(x, "username"), ReadString(x, "idnumber"), ReadString(x, "fullname"), HasStudentRole(x)))
                .Where(x => x.IsStudent)
                .ToList();
        }

        public async Task<MoodleCourseGradeDto?> GetCourseTotalAsync(int courseId, int userId, CancellationToken cancellationToken = default)
        {
            using var document = await CallAsync("gradereport_user_get_grade_items", new()
            {
                ["courseid"] = courseId.ToString(CultureInfo.InvariantCulture),
                ["userid"] = userId.ToString(CultureInfo.InvariantCulture)
            }, cancellationToken);

            if (!document.RootElement.TryGetProperty("usergrades", out var userGrades) || userGrades.GetArrayLength() == 0)
                return null;
            var items = userGrades[0].GetProperty("gradeitems");
            JsonElement? courseTotal = items.EnumerateArray().FirstOrDefault(x =>
                string.Equals(ReadString(x, "itemtype"), "course", StringComparison.OrdinalIgnoreCase));
            if (!courseTotal.HasValue || courseTotal.Value.ValueKind == JsonValueKind.Undefined)
                return null;
            var item = courseTotal.Value;
            var raw = ReadDecimal(item, "graderaw");
            var min = ReadDecimal(item, "grademin");
            var max = ReadDecimal(item, "grademax");
            var percentage = raw.HasValue && min.HasValue && max.HasValue && max.Value > min.Value
    ? Math.Round((raw.Value - min.Value) * 100m / (max.Value - min.Value), 2)
    : (decimal?)null;
            return new MoodleCourseGradeDto(raw, min, max, percentage, ReadBool(item, "hidden"), ReadBool(item, "overridden"), ReadNullableString(item, "feedback"));
        }

        public async Task<int?> FindUserByIdNumberAsync(string idNumber, CancellationToken cancellationToken = default)
        {
            using var document = await CallAsync("core_user_get_users_by_field", new()
            {
                ["field"] = "idnumber", ["values[0]"] = idNumber
            }, cancellationToken);
            if (document.RootElement.ValueKind != JsonValueKind.Array || document.RootElement.GetArrayLength() != 1)
                return null;
            return ReadInt(document.RootElement[0], "id");
        }

        public async Task UpdateSisGradeItemAsync(int courseId, string externalClassId, string itemName, decimal maximumValue,
            IReadOnlyList<MoodleGradeWriteDto> grades, CancellationToken cancellationToken = default)
        {
            var arguments = new Dictionary<string, string>
            {
                ["source"] = "Yulpana Gibbon SIS",
                ["courseid"] = courseId.ToString(CultureInfo.InvariantCulture),
                ["component"] = "local_namosa",
                ["activityid"] = StableActivityId(externalClassId).ToString(CultureInfo.InvariantCulture),
                ["itemnumber"] = "0",
                ["itemdetails[itemname]"] = itemName,
                ["itemdetails[idnumber]"] = $"namosa-{externalClassId}",
                ["itemdetails[gradetype]"] = "1",
                ["itemdetails[grademin]"] = "0",
                ["itemdetails[grademax]"] = maximumValue.ToString(CultureInfo.InvariantCulture)
            };
            for (var index = 0; index < grades.Count; index++)
            {
                arguments[$"grades[{index}][studentid]"] = grades[index].MoodleUserId.ToString(CultureInfo.InvariantCulture);
                arguments[$"grades[{index}][grade]"] = grades[index].Grade.ToString(CultureInfo.InvariantCulture);
                if (!string.IsNullOrWhiteSpace(grades[index].Feedback))
                    arguments[$"grades[{index}][str_feedback]"] = grades[index].Feedback!;
            }
            using var result = await CallAsync("core_grades_update_grades", arguments, cancellationToken);
            if (result.RootElement.ValueKind == JsonValueKind.Number && result.RootElement.GetInt32() != 0)
                throw new InvalidOperationException($"Moodle rejected the grade update with status {result.RootElement.GetInt32()}.");
        }

        private static int StableActivityId(string value)
        {
            unchecked
            {
                var hash = 17;
                foreach (var character in value) hash = hash * 31 + character;
                return Math.Abs(hash == int.MinValue ? int.MaxValue : hash);
            }
        }

        private async Task<JsonDocument> CallAsync(string function, Dictionary<string, string> arguments, CancellationToken cancellationToken)
        {
            var connection = await ResolveConnectionAsync();
            arguments["wstoken"] = connection.Token;
            arguments["wsfunction"] = function;
            arguments["moodlewsrestformat"] = "json";
            using var response = await _httpClient.PostAsync(connection.Endpoint, new FormUrlEncodedContent(arguments), cancellationToken);
            response.EnsureSuccessStatusCode();
            var json = await response.Content.ReadAsStringAsync(cancellationToken);
            var document = JsonDocument.Parse(json);
            if (document.RootElement.ValueKind == JsonValueKind.Object && document.RootElement.TryGetProperty("exception", out var exception))
            {
                var message = document.RootElement.TryGetProperty("message", out var detail) ? detail.GetString() : exception.GetString();
                document.Dispose();
                throw new InvalidOperationException($"Moodle web service {function} failed: {message}");
            }
            return document;
        }

        private static string ReadString(JsonElement element, string name) => ReadNullableString(element, name) ?? string.Empty;
        private static string? ReadNullableString(JsonElement element, string name) => element.TryGetProperty(name, out var value) && value.ValueKind != JsonValueKind.Null ? value.ToString() : null;
        private static int ReadInt(JsonElement element, string name) => element.TryGetProperty(name, out var value) && value.TryGetInt32(out var result) ? result : 0;
        private static bool ReadBool(JsonElement element, string name) => element.TryGetProperty(name, out var value) && (value.ValueKind == JsonValueKind.True || value.ToString() == "1");
        private static decimal? ReadDecimal(JsonElement element, string name) => element.TryGetProperty(name, out var value) && value.ValueKind != JsonValueKind.Null && decimal.TryParse(value.ToString(), NumberStyles.Any, CultureInfo.InvariantCulture, out var result) ? result : null;
        private static bool HasStudentRole(JsonElement element) => element.TryGetProperty("roles", out var roles) && roles.ValueKind == JsonValueKind.Array &&
            roles.EnumerateArray().Any(role => string.Equals(ReadString(role, "shortname"), "student", StringComparison.OrdinalIgnoreCase));

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
