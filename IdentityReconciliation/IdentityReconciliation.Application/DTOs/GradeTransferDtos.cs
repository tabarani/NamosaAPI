namespace IdentityReconciliation.Application.DTOs;

public sealed record MoodleCourseDto(int Id, string FullName, string ShortName, string IdNumber);
public sealed record MoodleEnrolledUserDto(int Id, string Username, string IdNumber, string FullName, bool IsStudent);
public sealed record MoodleCourseGradeDto(decimal? RawGrade, decimal? MinGrade, decimal? MaxGrade, decimal? Percentage, bool Hidden, bool Overridden, string? Feedback);

public sealed record GibbonClassMatchDto(bool Found, bool Ambiguous, string? GibbonCourseClassId, string? ClassName, string? Error);

public sealed record GibbonCourseTotalWriteDto(
    string GibbonCourseClassId,
    string GibbonPersonId,
    string ColumnKey,
    string ColumnName,
    decimal Value,
    decimal MaximumValue,
    bool IsPercentage,
    DateOnly CompletionDate,
    string ActorGibbonPersonId,
    bool ViewableStudents,
    bool ViewableParents,
    bool OverwriteExisting,
    string? Comment);

public sealed record GibbonGradeWriteResultDto(string ColumnId, string EntryId, bool ColumnCreated, bool EntryCreated, decimal Value);

public sealed record GradeTransferPolicy(
    bool Enabled,
    bool ManualTransferEnabled,
    bool ScheduledTransferEnabled,
    DateTimeOffset? WindowStart,
    DateTimeOffset? WindowEnd,
    bool TransferAsPercentage,
    bool RequireCompleteGrades,
    bool IncludeHiddenGrades,
    bool OverwriteExisting,
    string SemesterKey,
    string ColumnName,
    string ActorGibbonPersonId,
    bool ViewableStudents,
    bool ViewableParents)
{
    public bool IsWindowOpen(DateTimeOffset now) =>
        (!WindowStart.HasValue || now >= WindowStart.Value) &&
        (!WindowEnd.HasValue || now <= WindowEnd.Value);
}

public sealed record GradeTransferPreviewItem(
    int MoodleCourseId,
    string MoodleCourseName,
    string MoodleCourseIdNumber,
    string? GibbonCourseClassId,
    string Status,
    int EnrolledUsers,
    int TransferableGrades,
    int MissingGrades,
    int UnmappedUsers,
    string? Message);

public sealed record GradeTransferRunResult(Guid JobId, IReadOnlyList<GradeTransferPreviewItem> Courses);

public sealed record GibbonGradeCourseDto(string GibbonCourseClassId, string CourseName, string ClassName, int GradedStudents, decimal MaximumValue);
public sealed record GibbonStudentGradeDto(string GibbonPersonId, decimal Grade, string? Comment);
public sealed record MoodleGradeWriteDto(int MoodleUserId, decimal Grade, string? Feedback);
public sealed record GibbonToMoodleCourseStatus(string GibbonCourseClassId, string CourseName, int? MoodleCourseId, string? MoodleCourseName,
    int AvailableGrades, int MappedStudents, int UnmappedStudents, string Status, string? Message);
public sealed record GibbonToMoodleRunResult(Guid JobId, IReadOnlyList<GibbonToMoodleCourseStatus> Courses);
