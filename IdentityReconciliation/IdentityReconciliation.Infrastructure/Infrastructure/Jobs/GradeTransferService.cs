using System.Diagnostics;
using System.Globalization;
using System.Text.Json;
using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;

namespace IdentityReconciliation.Infrastructure.Jobs;

public sealed class GradeTransferService : IGradeTransferService
{
    private const string Section = "GradeTransfer";
    private readonly IControlCenterSettings _settings;
    private readonly IMoodleApiClient _moodle;
    private readonly IGibbonGradeRepository _gibbon;
    private readonly ISyncJobService _jobs;

    public GradeTransferService(IControlCenterSettings settings, IMoodleApiClient moodle, IGibbonGradeRepository gibbon, ISyncJobService jobs)
    {
        _settings = settings;
        _moodle = moodle;
        _gibbon = gibbon;
        _jobs = jobs;
    }

    public async Task<GradeTransferPolicy> GetPolicyAsync(CancellationToken cancellationToken = default)
    {
        var values = await _settings.GetSectionAsync(Section, cancellationToken);
        return new GradeTransferPolicy(
            Bool(values, "Enabled"), Bool(values, "ManualTransferEnabled", true), Bool(values, "ScheduledTransferEnabled"),
            Date(values, "WindowStart"), Date(values, "WindowEnd"), Bool(values, "TransferAsPercentage", true),
            Bool(values, "RequireCompleteGrades", true), Bool(values, "IncludeHiddenGrades"), Bool(values, "OverwriteExisting", true),
            Text(values, "SemesterKey", "semester"), Text(values, "ColumnName", "Moodle Semester Total"),
            Text(values, "ActorGibbonPersonId", string.Empty), Bool(values, "ViewableStudents", true), Bool(values, "ViewableParents", true));
    }

    public async Task SavePolicyAsync(GradeTransferPolicy policy, string? actor, CancellationToken cancellationToken = default)
    {
        var values = new Dictionary<string, string?>
        {
            ["Enabled"] = policy.Enabled.ToString(), ["ManualTransferEnabled"] = policy.ManualTransferEnabled.ToString(),
            ["ScheduledTransferEnabled"] = policy.ScheduledTransferEnabled.ToString(), ["WindowStart"] = policy.WindowStart?.ToString("O"),
            ["WindowEnd"] = policy.WindowEnd?.ToString("O"), ["TransferAsPercentage"] = policy.TransferAsPercentage.ToString(),
            ["RequireCompleteGrades"] = policy.RequireCompleteGrades.ToString(), ["IncludeHiddenGrades"] = policy.IncludeHiddenGrades.ToString(),
            ["OverwriteExisting"] = policy.OverwriteExisting.ToString(), ["SemesterKey"] = policy.SemesterKey,
            ["ColumnName"] = policy.ColumnName, ["ActorGibbonPersonId"] = policy.ActorGibbonPersonId,
            ["ViewableStudents"] = policy.ViewableStudents.ToString(), ["ViewableParents"] = policy.ViewableParents.ToString()
        };
        foreach (var item in values)
            await _settings.SetAsync(Section, item.Key, item.Value, false, actor, cancellationToken);
    }

    public async Task<IReadOnlyList<GradeTransferPreviewItem>> PreviewAsync(int? moodleCourseId = null, CancellationToken cancellationToken = default)
    {
        var policy = await GetPolicyAsync(cancellationToken);
        var courses = await _moodle.GetCoursesAsync(cancellationToken);
        if (moodleCourseId.HasValue)
            courses = courses.Where(x => x.Id == moodleCourseId.Value).ToList();
        var result = new List<GradeTransferPreviewItem>();
        foreach (var course in courses)
            result.Add((await InspectCourseAsync(course, policy, false, null, cancellationToken)).Preview);
        return result;
    }

    public async Task<GradeTransferRunResult> RunAsync(int? moodleCourseId, bool manual, string? requestedBy, CancellationToken cancellationToken = default)
    {
        var policy = await GetPolicyAsync(cancellationToken);
        if (!policy.Enabled)
            throw new InvalidOperationException("Moodle-to-Gibbon grade transfer is disabled by the administrator.");
        if (string.IsNullOrWhiteSpace(policy.SemesterKey) || string.IsNullOrWhiteSpace(policy.ActorGibbonPersonId))
            throw new InvalidOperationException("Configure the semester key and Gibbon actor person ID before transferring grades.");
        if (manual && !policy.ManualTransferEnabled)
            throw new InvalidOperationException("Manual grade transfer is disabled by the administrator.");
        if (!manual && (!policy.ScheduledTransferEnabled || !policy.IsWindowOpen(DateTimeOffset.UtcNow)))
            throw new InvalidOperationException("Scheduled grade transfer is disabled or outside its configured date range.");

        var job = await _jobs.CreateAsync("CourseTotalGrade", "MoodleToGibbon", manual ? "Manual" : "Scheduled", requestedBy, JsonSerializer.Serialize(policy), cancellationToken);
        var courses = await _moodle.GetCoursesAsync(cancellationToken);
        if (moodleCourseId.HasValue)
            courses = courses.Where(x => x.Id == moodleCourseId.Value).ToList();
        if (courses.Count == 0)
            throw new InvalidOperationException("The requested Moodle course was not found.");

        var result = new List<GradeTransferPreviewItem>();
        foreach (var course in courses)
            result.Add((await InspectCourseAsync(course, policy, true, job.Id, cancellationToken)).Preview);
        return new GradeTransferRunResult(job.Id, result);
    }

    private async Task<(GradeTransferPreviewItem Preview, int Written)> InspectCourseAsync(MoodleCourseDto course, GradeTransferPolicy policy, bool write, Guid? jobId, CancellationToken cancellationToken)
    {
        var match = await _gibbon.ResolveClassAsync(course.IdNumber, cancellationToken);
        if (!match.Found)
        {
            if (write && jobId.HasValue)
            {
                var validation = await _jobs.AddOperationAsync(jobId.Value, "Course", "ValidateMapping", "Moodle", course.Id.ToString(), "Gibbon", null,
                    $"{jobId}:{course.Id}:mapping", JsonSerializer.Serialize(new { course.IdNumber }), cancellationToken);
                await _jobs.RecordAttemptAsync(validation.Id, false, null, 0, "Validation", match.Error, null, cancellationToken);
            }
            return (new(course.Id, course.FullName, course.IdNumber, null, "MappingRequired", 0, 0, 0, 0, match.Error), 0);
        }

        var users = await _moodle.GetEnrolledUsersAsync(course.Id, cancellationToken);
        var transferable = 0; var missing = 0; var unmapped = 0; var written = 0;
        var ready = new List<(MoodleEnrolledUserDto User, MoodleCourseGradeDto Grade, decimal Value)>();
        foreach (var user in users)
        {
            if (string.IsNullOrWhiteSpace(user.IdNumber) || !await _gibbon.PersonExistsAsync(user.IdNumber, cancellationToken))
            {
                unmapped++;
                continue;
            }
            var grade = await _moodle.GetCourseTotalAsync(course.Id, user.Id, cancellationToken);
            var value = policy.TransferAsPercentage ? grade?.Percentage : grade?.RawGrade;
            if (grade is null || !value.HasValue || (!policy.IncludeHiddenGrades && grade.Hidden))
            {
                missing++;
                continue;
            }
            transferable++;
            ready.Add((user, grade, value.Value));
        }

        var blocked = users.Count == 0 || transferable == 0 || (policy.RequireCompleteGrades && (missing > 0 || unmapped > 0));
        if (write && jobId.HasValue && blocked)
        {
            var validation = await _jobs.AddOperationAsync(jobId.Value, "Course", "ValidateCompleteness", "Moodle", course.Id.ToString(), "Gibbon", match.GibbonCourseClassId,
                $"{jobId}:{course.Id}:completeness", JsonSerializer.Serialize(new { missing, unmapped }), cancellationToken);
            await _jobs.RecordAttemptAsync(validation.Id, false, null, 0, "Validation", "Course transfer blocked because grades or identity mappings are incomplete.", null, cancellationToken);
        }
        if (write && jobId.HasValue && !blocked)
        {
            foreach (var item in ready)
            {
                var user = item.User;
                var grade = item.Grade;
                var value = item.Value;

            var operation = await _jobs.AddOperationAsync(jobId.Value, "CourseGrade", "Upsert", "Moodle", $"{course.Id}:{user.Id}", "Gibbon", $"{match.GibbonCourseClassId}:{user.IdNumber}", $"{jobId}:{course.Id}:{user.Id}", JsonSerializer.Serialize(new { value, policy.TransferAsPercentage }), cancellationToken);
            for (var attempt = 1; attempt <= 3; attempt++)
            {
                var timer = Stopwatch.StartNew();
                try
                {
                    await _gibbon.UpsertCourseTotalAsync(new(
                        match.GibbonCourseClassId!, user.IdNumber, $"{policy.SemesterKey}:{course.Id}", policy.ColumnName,
                        value, policy.TransferAsPercentage ? 100m : grade.MaxGrade ?? 100m, policy.TransferAsPercentage,
                        DateOnly.FromDateTime(DateTime.UtcNow), policy.ActorGibbonPersonId, policy.ViewableStudents,
                        policy.ViewableParents, policy.OverwriteExisting,
                        $"Moodle course total ({(policy.TransferAsPercentage ? "percentage" : "raw score")}); source course {course.Id}."), cancellationToken);
                    timer.Stop(); written++;
                    await _jobs.RecordAttemptAsync(operation.Id, true, null, timer.ElapsedMilliseconds, null, "Grade transferred successfully.", null, cancellationToken);
                    break;
                }
                catch (Exception ex) when (ex is not OperationCanceledException)
                {
                    timer.Stop();
                    DateTime? retryAt = attempt < 3 ? DateTime.UtcNow.AddSeconds(attempt) : null;
                    await _jobs.RecordAttemptAsync(operation.Id, false, null, timer.ElapsedMilliseconds, "Transfer", Sanitize(ex.Message), retryAt, cancellationToken);
                    if (attempt < 3)
                        await Task.Delay(TimeSpan.FromSeconds(attempt), cancellationToken);
                }
            }
            }
        }

        var status = blocked ? "BlockedIncomplete" : write ? "Transferred" : "Ready";
        var message = blocked
            ? "No grades were written: the course has no transferable student totals, or some totals/identity mappings are incomplete."
            : write ? $"Transferred {written} of {transferable} available grades." : null;
        return (new(course.Id, course.FullName, course.IdNumber, match.GibbonCourseClassId, status, users.Count, transferable, missing, unmapped, message), written);
    }

    private static string Sanitize(string value) => value.Length > 500 ? value[..500] : value;
    private static bool Bool(IReadOnlyDictionary<string, string?> values, string key, bool fallback = false) => values.TryGetValue(key, out var value) && bool.TryParse(value, out var parsed) ? parsed : fallback;
    private static string Text(IReadOnlyDictionary<string, string?> values, string key, string fallback) => values.TryGetValue(key, out var value) && !string.IsNullOrWhiteSpace(value) ? value : fallback;
    private static DateTimeOffset? Date(IReadOnlyDictionary<string, string?> values, string key) => values.TryGetValue(key, out var value) && DateTimeOffset.TryParse(value, CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind, out var parsed) ? parsed : null;
}
