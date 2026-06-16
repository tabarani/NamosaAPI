using IdentityReconciliation.Application.Common;
using IdentityReconciliation.Infrastructure.Data;
using IdentityReconciliation.Infrastructure.OpenIddict;
using Microsoft.AspNetCore.HttpOverrides;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using OpenIddict.Abstractions;
using Serilog;
using static OpenIddict.Abstractions.OpenIddictConstants;

var builder = WebApplication.CreateBuilder(args);

builder.Host.UseSerilog((context, services, configuration) => configuration
    .ReadFrom.Configuration(context.Configuration)
    .ReadFrom.Services(services)
    .Enrich.FromLogContext()
    .Enrich.WithMachineName()
    .Enrich.WithEnvironmentName()
    .WriteTo.Console(new Serilog.Formatting.Json.JsonFormatter())
    .WriteTo.File(new Serilog.Formatting.Json.JsonFormatter(), "logs/identity-reconciliation-.json", rollingInterval: RollingInterval.Day));

// Add application and infrastructure services
builder.Services.AddApplication();
builder.Services.AddInfrastructure(builder.Configuration);

// Add ASP.NET Core Identity
builder.Services.AddIdentity<IdentityUser, IdentityRole>(options =>
{
    options.Password.RequireDigit = true;
    options.Password.RequiredLength = 8;
    options.Password.RequireNonAlphanumeric = false;
    options.Password.RequireUppercase = true;
    options.Password.RequireLowercase = true;
})
.AddEntityFrameworkStores<AppDbContext>()
.AddDefaultTokenProviders();

// Add OpenIddict server
builder.Services.AddOpenIddict()
    .AddCore(options =>
    {
        options.UseEntityFrameworkCore()
               .UseDbContext<AppDbContext>();
    })
    .AddServer(options =>
    {
        options.SetAuthorizationEndpointUris("/connect/authorize")
               .SetTokenEndpointUris("/connect/token")
               .SetEndSessionEndpointUris("/connect/logout")
               .SetUserInfoEndpointUris("/connect/userinfo");

        options.AllowAuthorizationCodeFlow()
               .AllowRefreshTokenFlow();

        // Register custom scopes
        options.RegisterScopes(
            Scopes.Email,
            Scopes.Profile,
            Scopes.Roles,
            "gibbon_id",
            "moodle_id"
        );

        // In production, replace these with real X.509 certificates:
        // options.AddEncryptionCertificate(new X509Certificate2("cert.pfx", "password"));
        // options.AddSigningCertificate(new X509Certificate2("cert.pfx", "password"));
        options.AddDevelopmentEncryptionCertificate()
               .AddDevelopmentSigningCertificate();

        // Set issuer URL for production (IP or domain)
        var issuerUrl = builder.Configuration["OpenIddict:IssuerUri"] ?? "https://144.91.66.114";
        options.SetIssuer(new Uri(issuerUrl));

        options.UseAspNetCore()
               .EnableAuthorizationEndpointPassthrough()
               .EnableTokenEndpointPassthrough()
               .EnableEndSessionEndpointPassthrough()
               .EnableUserInfoEndpointPassthrough()
               .EnableStatusCodePagesIntegration()
               .DisableTransportSecurityRequirement(); // Behind Nginx reverse proxy
    })
    .AddValidation(options =>
    {
        options.UseLocalServer();
        options.UseAspNetCore();
    });

// Register CustomProfileService as OpenIddict server event handler
builder.Services.AddScoped<CustomProfileService>();

// Register the client/user seed service — runs on startup
builder.Services.AddHostedService<ClientSeedService>();

// Add controllers and Razor Pages
builder.Services.AddControllers();
builder.Services.AddEndpointsApiExplorer();
builder.Services.AddSwaggerGen(options =>
{
    options.SwaggerDoc("v1", new Microsoft.OpenApi.Models.OpenApiInfo
    {
        Title = "Identity Reconciliation API",
        Version = "v1",
        Description = "Administrative reconciliation and OpenID Connect endpoints for Namosa identity services."
    });
});
builder.Services.AddRazorPages(options =>
{
    // Protect /Admin folder with Authorization Policy requiring "Admin" role
    options.Conventions.AuthorizeFolder("/Admin", "Admin");
});

// Add CORS policy — restrict to known origins in production
builder.Services.AddCors(options =>
{
    options.AddPolicy("SsoClients", policy =>
    {
        policy.WithOrigins(
                "https://nbs.edu.mr",       // Gibbon + Moodle
                "https://144.91.66.114"      // IdP itself
            )
            .AllowAnyMethod()
            .AllowAnyHeader()
            .AllowCredentials();
    });
});

var app = builder.Build();

// Configure forwarded headers BEFORE any other middleware
app.UseForwardedHeaders(new ForwardedHeadersOptions
{
    ForwardedHeaders = ForwardedHeaders.XForwardedFor | ForwardedHeaders.XForwardedProto,
    ForwardLimit = null,
    RequireHeaderSymmetry = false
});

// Configure the HTTP request pipeline
if (!app.Environment.IsDevelopment())
{
    app.UseExceptionHandler("/Error");
    app.UseHsts();
}

// Note: HTTPS is handled by Apache reverse proxy, not by Kestrel
// app.UseHttpsRedirection();
app.UseSerilogRequestLogging();

app.UseStaticFiles();

app.UseSwagger(options =>
{
    options.RouteTemplate = "api/docs/{documentName}/swagger.json";
});
app.UseSwaggerUI(options =>
{
    options.SwaggerEndpoint("/api/docs/v1/swagger.json", "Identity Reconciliation API v1");
    options.SwaggerEndpoint("/api/docs/gibbon/swagger.json", "Namosa Gibbon PHP APIs v1");
    options.RoutePrefix = "api/docs";
});

app.UseRouting();

app.UseCors("SsoClients");

app.UseAuthentication();
app.UseAuthorization();

app.MapRazorPages();
app.MapControllers();

app.Run();