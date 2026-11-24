using System.Globalization;
using Dapper;
using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Enums;
using Microsoft.Extensions.Configuration;
using MySqlConnector;

namespace IdentityReconciliation.Infrastructure.Repositories;

public sealed class GibbonGradeRepository : IGibbonGradeRepository
{
    private readonly IConfiguration _configuration;
    private readonly IIntegrationConnectionResolver _connections;

    public GibbonGradeRepository(IConfiguration configuration, IIntegrationConnectionResolver connections)
    {
        _configuration = configuration;
        _connections = connections;
    }

    public async Task<GibbonClassMatchDto> ResolveClassAsync(string externalClassId, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(externalClassId))
            return new(false, false, null, null, "The Moodle course idnumber is empty. Set it to the Gibbon Course Class ID.");

        await using var connection = await OpenAsync(cancellationToken);
        const string sql = """
            SELECT cc.gibbonCourseClassID AS Id, CONCAT(c.name, ' - ', cc.name) AS Name
            FROM gibbonCourseClass cc
            INNER JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            WHERE cc.gibbonCourseClassID = @ExternalClassId
            """;
        var rows = (await connection.QueryAsync<(string Id, string Name)>(new CommandDefinition(sql, new { ExternalClassId = externalClassId }, cancellationToken: cancellationToken))).ToList();
        return rows.Count switch
        {
            0 => new(false, false, null, null, $"No Gibbon class matches Course Class ID '{externalClassId}'."),
            > 1 => new(false, true, null, null, $"More than one Gibbon class matches '{externalClassId}'."),
            _ => new(true, false, rows[0].Id, rows[0].Name, null)
        };
    }

    public async Task<bool> PersonExistsAsync(string gibbonPersonId, CancellationToken cancellationToken = default)
    {
        await using var connection = await OpenAsync(cancellationToken);
        const string sql = "SELECT COUNT(*) FROM gibbonPerson WHERE gibbonPersonID = @GibbonPersonId AND status = 'Full'";
        return await connection.ExecuteScalarAsync<int>(new CommandDefinition(sql, new { GibbonPersonId = gibbonPersonId }, cancellationToken: cancellationToken)) == 1;
    }

    public async Task<GibbonGradeWriteResultDto> UpsertCourseTotalAsync(GibbonCourseTotalWriteDto request, CancellationToken cancellationToken = default)
    {
        await using var connection = await OpenAsync(cancellationToken);
        await using var transaction = await connection.BeginTransactionAsync(cancellationToken);
        var marker = $"[Yulpana:MoodleCourseTotal:{request.ColumnKey}]";
        const string findColumn = """
            SELECT gibbonMarkbookColumnID FROM gibbonMarkbookColumn
            WHERE gibbonCourseClassID = @GibbonCourseClassId AND description LIKE CONCAT(@Marker, '%')
            LIMIT 2
            """;
        var columns = (await connection.QueryAsync<string>(new CommandDefinition(findColumn, new { request.GibbonCourseClassId, Marker = marker }, transaction, cancellationToken: cancellationToken))).ToList();
        if (columns.Count > 1)
            throw new InvalidOperationException("Multiple Yulpana course-total columns exist for the same class and semester. Resolve the duplicate before transferring grades.");

        var columnCreated = columns.Count == 0;
        string columnId;
        if (columnCreated)
        {
            const string insertColumn = """
                INSERT INTO gibbonMarkbookColumn
                    (gibbonCourseClassID, type, name, description, date, sequenceNumber, attachment,
                     attainment, gibbonScaleIDAttainment, attainmentWeighting, attainmentRaw, attainmentRawMax,
                     effort, comment, uploadedResponse, complete, completeDate, viewableStudents, viewableParents,
                     gibbonPersonIDCreator, gibbonPersonIDLastEdit)
                VALUES
                    (@GibbonCourseClassId, 'Semester Total', @ColumnName, @Description, @CompletionDate, 0, '',
                     'Y', NULL, 100.00, 'Y', @MaximumValue,
                     'N', 'Y', 'N', 'Y', @CompletionDate, @Students, @Parents,
                     @ActorGibbonPersonId, @ActorGibbonPersonId);
                SELECT LAST_INSERT_ID();
                """;
            columnId = await connection.ExecuteScalarAsync<string>(new CommandDefinition(insertColumn, new
            {
                request.GibbonCourseClassId,
                ColumnName = Truncate(request.ColumnName, 40),
                Description = marker + " Imported from Moodle by Yulpana Control Center.",
                CompletionDate = request.CompletionDate.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
                MaximumValue = request.MaximumValue,
                Students = request.ViewableStudents ? "Y" : "N",
                Parents = request.ViewableParents ? "Y" : "N",
                request.ActorGibbonPersonId
            }, transaction, cancellationToken: cancellationToken)) ?? throw new InvalidOperationException("Gibbon did not return the new markbook column ID.");
        }
        else
        {
            columnId = columns[0];
        }

        const string findEntry = """
            SELECT gibbonMarkbookEntryID FROM gibbonMarkbookEntry
            WHERE gibbonMarkbookColumnID = @ColumnId AND gibbonPersonIDStudent = @GibbonPersonId
            LIMIT 1
            """;
        var entryId = await connection.QuerySingleOrDefaultAsync<string>(new CommandDefinition(findEntry, new { ColumnId = columnId, request.GibbonPersonId }, transaction, cancellationToken: cancellationToken));
        var entryCreated = string.IsNullOrWhiteSpace(entryId);
        var value = request.Value.ToString("0.##", CultureInfo.InvariantCulture);
        var comment = string.IsNullOrWhiteSpace(request.Comment) ? "Imported from Moodle course total." : request.Comment;

        if (entryCreated)
        {
            const string insertEntry = """
                INSERT INTO gibbonMarkbookEntry
                    (gibbonMarkbookColumnID, gibbonPersonIDStudent, modifiedAssessment, attainmentValueRaw, comment, gibbonPersonIDLastEdit)
                VALUES (@ColumnId, @GibbonPersonId, 'N', @Value, @Comment, @ActorGibbonPersonId);
                SELECT LAST_INSERT_ID();
                """;
            entryId = await connection.ExecuteScalarAsync<string>(new CommandDefinition(insertEntry, new { ColumnId = columnId, request.GibbonPersonId, Value = value, Comment = comment, request.ActorGibbonPersonId }, transaction, cancellationToken: cancellationToken));
        }
        else if (request.OverwriteExisting)
        {
            const string updateEntry = """
                UPDATE gibbonMarkbookEntry SET attainmentValueRaw = @Value, comment = @Comment,
                    gibbonPersonIDLastEdit = @ActorGibbonPersonId
                WHERE gibbonMarkbookEntryID = @EntryId
                """;
            await connection.ExecuteAsync(new CommandDefinition(updateEntry, new { Value = value, Comment = comment, request.ActorGibbonPersonId, EntryId = entryId }, transaction, cancellationToken: cancellationToken));
        }

        await transaction.CommitAsync(cancellationToken);
        return new(columnId, entryId!, columnCreated, entryCreated, request.Value);
    }

    public async Task<IReadOnlyList<GibbonGradeCourseDto>> GetGradeCoursesAsync(string semesterKey, CancellationToken cancellationToken = default)
    {
        await using var connection = await OpenAsync(cancellationToken);
        const string sql = """
            SELECT cc.gibbonCourseClassID AS GibbonCourseClassId, c.name AS CourseName, cc.name AS ClassName,
                   COUNT(e.gibbonMarkbookEntryID) AS GradedStudents,
                   COALESCE(col.attainmentRawMax, 100) AS MaximumValue
            FROM gibbonMarkbookColumn col
            INNER JOIN gibbonCourseClass cc ON cc.gibbonCourseClassID = col.gibbonCourseClassID
            INNER JOIN gibbonCourse c ON c.gibbonCourseID = cc.gibbonCourseID
            LEFT JOIN gibbonMarkbookEntry e ON e.gibbonMarkbookColumnID = col.gibbonMarkbookColumnID
                AND e.attainmentValueRaw IS NOT NULL AND e.attainmentValueRaw <> ''
            WHERE col.complete = 'Y'
              AND (col.type = 'Semester Total' OR col.description LIKE @Marker)
              AND col.gibbonMarkbookColumnID = (
                  SELECT MAX(c2.gibbonMarkbookColumnID) FROM gibbonMarkbookColumn c2
                  WHERE c2.gibbonCourseClassID = col.gibbonCourseClassID AND c2.complete = 'Y'
                    AND (c2.type = 'Semester Total' OR c2.description LIKE @Marker))
            GROUP BY cc.gibbonCourseClassID, c.name, cc.name, col.attainmentRawMax
            ORDER BY c.name, cc.name
            """;
        var marker = $"[Yulpana:MoodleCourseTotal:{semesterKey}:%";
        var rows = await connection.QueryAsync<GibbonGradeCourseDto>(new CommandDefinition(sql, new { Marker = marker }, cancellationToken: cancellationToken));
        return rows.ToList();
    }

    public async Task<IReadOnlyList<GibbonStudentGradeDto>> GetCourseGradesAsync(string gibbonCourseClassId, string semesterKey, CancellationToken cancellationToken = default)
    {
        await using var connection = await OpenAsync(cancellationToken);
        const string sql = """
            SELECT e.gibbonPersonIDStudent AS GibbonPersonId,
                   CAST(e.attainmentValueRaw AS DECIMAL(10,2)) AS Grade, e.comment AS Comment
            FROM gibbonMarkbookEntry e
            INNER JOIN gibbonMarkbookColumn col ON col.gibbonMarkbookColumnID = e.gibbonMarkbookColumnID
            WHERE col.gibbonCourseClassID = @GibbonCourseClassId AND col.complete = 'Y'
              AND e.attainmentValueRaw IS NOT NULL AND e.attainmentValueRaw <> ''
              AND (col.type = 'Semester Total' OR col.description LIKE @Marker)
            ORDER BY col.gibbonMarkbookColumnID DESC
            """;
        var marker = $"[Yulpana:MoodleCourseTotal:{semesterKey}:%";
        var rows = (await connection.QueryAsync<GibbonStudentGradeDto>(new CommandDefinition(sql,
            new { GibbonCourseClassId = gibbonCourseClassId, Marker = marker }, cancellationToken: cancellationToken))).ToList();
        return rows.GroupBy(x => x.GibbonPersonId).Select(x => x.First()).ToList();
    }

    private async Task<MySqlConnection> OpenAsync(CancellationToken cancellationToken)
    {
        var configured = await _connections.ResolveAsync(IntegrationSystemType.Gibbon, cancellationToken);
        var connectionString = configured?.Secrets.GetValueOrDefault("DatabaseConnection") ?? _configuration.GetConnectionString("GibbonDb");
        if (string.IsNullOrWhiteSpace(connectionString))
            throw new InvalidOperationException("Configure an enabled Gibbon database connection in the Yulpana Control Center.");
        var connection = new MySqlConnection(connectionString);
        await connection.OpenAsync(cancellationToken);
        return connection;
    }

    private static string Truncate(string value, int length) => value.Length <= length ? value : value[..length];
}
