using System.Diagnostics;
using System.Text.Json;
using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;

namespace IdentityReconciliation.Infrastructure.Jobs;

public sealed class GibbonToMoodleGradeService : IGibbonToMoodleGradeService
{
    private readonly IGibbonGradeRepository _gibbon;
    private readonly IMoodleApiClient _moodle;
    private readonly IControlCenterSettings _settings;
    private readonly ISyncJobService _jobs;

    public GibbonToMoodleGradeService(IGibbonGradeRepository gibbon, IMoodleApiClient moodle, IControlCenterSettings settings, ISyncJobService jobs)
    { _gibbon = gibbon; _moodle = moodle; _settings = settings; _jobs = jobs; }

    public async Task<IReadOnlyList<GibbonToMoodleCourseStatus>> PreviewAsync(IReadOnlyCollection<string>? selectedClassIds = null, CancellationToken cancellationToken = default)
    {
        var semesterKey = await SemesterKeyAsync(cancellationToken);
        var sources = await _gibbon.GetGradeCoursesAsync(semesterKey, cancellationToken);
        if (selectedClassIds is { Count: > 0 }) sources = sources.Where(x => selectedClassIds.Contains(x.GibbonCourseClassId)).ToList();
        var moodleCourses = await _moodle.GetCoursesAsync(cancellationToken);
        var result = new List<GibbonToMoodleCourseStatus>();
        foreach (var source in sources)
        {
            var target = moodleCourses.SingleOrDefault(x => string.Equals(x.IdNumber, source.GibbonCourseClassId, StringComparison.OrdinalIgnoreCase));
            if (target is null)
            {
                result.Add(new(source.GibbonCourseClassId, $"{source.CourseName} - {source.ClassName}", null, null, source.GradedStudents, 0, source.GradedStudents, "MappingRequired", "No Moodle course has this Gibbon class ID in course.idnumber."));
                continue;
            }
            var grades = await _gibbon.GetCourseGradesAsync(source.GibbonCourseClassId, semesterKey, cancellationToken);
            var mapped = 0;
            foreach (var grade in grades)
            {
                var moodleUserId = await _moodle.FindUserByIdNumberAsync(grade.GibbonPersonId, cancellationToken);
                if (moodleUserId.HasValue) mapped++;
            }
            var unmapped = grades.Count - mapped;
            result.Add(new(source.GibbonCourseClassId, $"{source.CourseName} - {source.ClassName}", target.Id, target.FullName,
                grades.Count, mapped, unmapped, unmapped == 0 && grades.Count > 0 ? "Ready" : "Blocked", unmapped > 0 ? "Some Gibbon students have no unique Moodle idnumber mapping." : null));
        }
        return result;
    }

    public async Task<GibbonToMoodleRunResult> RunAsync(IReadOnlyCollection<string> selectedClassIds, string? requestedBy, CancellationToken cancellationToken = default)
    {
        if (selectedClassIds.Count == 0) throw new InvalidOperationException("Select at least one course.");
        if (!bool.TryParse(await _settings.GetAsync("GradeTransfer", "GibbonToMoodleEnabled", cancellationToken), out var enabled) || !enabled)
            throw new InvalidOperationException("Gibbon-to-Moodle grade transfer is disabled by the administrator.");
        var semesterKey = await SemesterKeyAsync(cancellationToken);
        var job = await _jobs.CreateAsync("CourseTotalGrade", "GibbonToMoodle", "ManualSelection", requestedBy,
            JsonSerializer.Serialize(new { semesterKey, selectedClassIds }), cancellationToken);
        var sources = await _gibbon.GetGradeCoursesAsync(semesterKey, cancellationToken);
        var moodleCourses = await _moodle.GetCoursesAsync(cancellationToken);
        var results = new List<GibbonToMoodleCourseStatus>();
        foreach (var classId in selectedClassIds.Distinct(StringComparer.OrdinalIgnoreCase))
        {
            var source = sources.SingleOrDefault(x => x.GibbonCourseClassId == classId);
            var target = moodleCourses.SingleOrDefault(x => string.Equals(x.IdNumber, classId, StringComparison.OrdinalIgnoreCase));
            if (source is null || target is null)
            {
                await RecordValidationAsync(job.Id, classId, "The selected Gibbon grade source or Moodle course mapping was not found.", cancellationToken);
                results.Add(new(classId, source?.CourseName ?? classId, target?.Id, target?.FullName, 0, 0, 0, "MappingRequired", "Source or target mapping not found."));
                continue;
            }
            var sourceGrades = await _gibbon.GetCourseGradesAsync(classId, semesterKey, cancellationToken);
            var writes = new List<MoodleGradeWriteDto>();
            foreach (var grade in sourceGrades)
            {
                var userId = await _moodle.FindUserByIdNumberAsync(grade.GibbonPersonId, cancellationToken);
                if (userId.HasValue) writes.Add(new(userId.Value, grade.Grade, grade.Comment));
            }
            var unmapped = sourceGrades.Count - writes.Count;
            if (sourceGrades.Count == 0 || unmapped > 0)
            {
                await RecordValidationAsync(job.Id, classId, "Course blocked: no grades or one or more students are unmapped.", cancellationToken);
                results.Add(new(classId, $"{source.CourseName} - {source.ClassName}", target.Id, target.FullName, sourceGrades.Count, writes.Count, unmapped, "Blocked", "No grades were written."));
                continue;
            }
            var operation = await _jobs.AddOperationAsync(job.Id, "CourseGradeSet", "Upsert", "Gibbon", classId, "Moodle", target.Id.ToString(),
                $"{job.Id}:{classId}:{target.Id}", JsonSerializer.Serialize(new { grades = writes.Count, source.MaximumValue }), cancellationToken);
            var succeeded = false;
            for (var attempt = 1; attempt <= 3 && !succeeded; attempt++)
            {
                var timer = Stopwatch.StartNew();
                try
                {
                    await _moodle.UpdateSisGradeItemAsync(target.Id, classId, $"Gibbon Semester Total ({semesterKey})", source.MaximumValue, writes, cancellationToken);
                    timer.Stop(); succeeded = true;
                    await _jobs.RecordAttemptAsync(operation.Id, true, null, timer.ElapsedMilliseconds, null, "Grades transferred successfully.", null, cancellationToken);
                }
                catch (Exception ex) when (ex is not OperationCanceledException)
                {
                    timer.Stop(); DateTime? retryAt = attempt < 3 ? DateTime.UtcNow.AddSeconds(attempt) : null;
                    await _jobs.RecordAttemptAsync(operation.Id, false, null, timer.ElapsedMilliseconds, "Transfer", ex.Message.Length > 500 ? ex.Message[..500] : ex.Message, retryAt, cancellationToken);
                    if (attempt < 3) await Task.Delay(TimeSpan.FromSeconds(attempt), cancellationToken);
                }
            }
            results.Add(new(classId, $"{source.CourseName} - {source.ClassName}", target.Id, target.FullName, sourceGrades.Count, writes.Count, 0,
                succeeded ? "Transferred" : "TransferFailed", succeeded ? null : "See the operation log for the final error."));
        }
        return new(job.Id, results);
    }

    private async Task<string> SemesterKeyAsync(CancellationToken token) =>
        await _settings.GetAsync("GradeTransfer", "SemesterKey", token) is { Length: > 0 } key ? key : "semester";

    private async Task RecordValidationAsync(Guid jobId, string classId, string message, CancellationToken token)
    {
        var operation = await _jobs.AddOperationAsync(jobId, "Course", "Validate", "Gibbon", classId, "Moodle", null, $"{jobId}:{classId}:validation", null, token);
        await _jobs.RecordAttemptAsync(operation.Id, false, null, 0, "Validation", message, null, token);
    }
}
