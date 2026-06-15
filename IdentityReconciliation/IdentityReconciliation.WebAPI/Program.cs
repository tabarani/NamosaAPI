using IdentityReconciliation.Infrastructure.Data;
using IdentityReconciliation.Infrastructure.OpenIddict;
using Microsoft.AspNetCore.HttpOverrides;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using OpenIddict.Abstractions;
using static OpenIddict.Abstractions.OpenIddictConstants;

var builder = WebApplication.CreateBuilder(args);

// Add infrastructure services
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
builder.Services.AddRazorPages();

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
app.UseStaticFiles();

app.UseRouting();

app.UseCors("SsoClients");

app.UseAuthentication();
app.UseAuthorization();

app.MapRazorPages();
app.MapControllers();

app.Run();