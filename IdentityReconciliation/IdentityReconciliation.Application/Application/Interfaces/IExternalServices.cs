using IdentityReconciliation.Application.DTOs;

namespace IdentityReconciliation.Application.Interfaces
{
    public interface IMoodleApiClient
    {
        Task<List<MoodleUserDto>> GetAllUsersAsync();
        Task UpdateUserAuthMethod(int userId, string authMethod = "oidc");
        Task<IReadOnlyList<MoodleCourseDto>> GetCoursesAsync(CancellationToken cancellationToken = default);
        Task<IReadOnlyList<MoodleEnrolledUserDto>> GetEnrolledUsersAsync(int courseId, CancellationToken cancellationToken = default);
        Task<MoodleCourseGradeDto?> GetCourseTotalAsync(int courseId, int userId, CancellationToken cancellationToken = default);
        Task<int?> FindUserByIdNumberAsync(string idNumber, CancellationToken cancellationToken = default);
        Task UpdateSisGradeItemAsync(int courseId, string externalClassId, string itemName, decimal maximumValue,
            IReadOnlyList<MoodleGradeWriteDto> grades, CancellationToken cancellationToken = default);
    }

    public interface IGibbonPersonRepository
    {
        Task<IEnumerable<GibbonPersonDto>> GetAllActivePersonsAsync();
    }

    public interface IGibbonGradeRepository
    {
        Task<GibbonClassMatchDto> ResolveClassAsync(string externalClassId, CancellationToken cancellationToken = default);
        Task<bool> PersonExistsAsync(string gibbonPersonId, CancellationToken cancellationToken = default);
        Task<GibbonGradeWriteResultDto> UpsertCourseTotalAsync(GibbonCourseTotalWriteDto request, CancellationToken cancellationToken = default);
        Task<IReadOnlyList<GibbonGradeCourseDto>> GetGradeCoursesAsync(string semesterKey, CancellationToken cancellationToken = default);
        Task<IReadOnlyList<GibbonStudentGradeDto>> GetCourseGradesAsync(string gibbonCourseClassId, string semesterKey, CancellationToken cancellationToken = default);
    }
}
