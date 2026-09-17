import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Component, inject, OnInit } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';

type RoleCode = 'contratacion_publica' | 'bienes_activos_fijos' | 'contador' | 'director' | 'operador';
type SessionData = { token: string; expiresAt: number; user: { id: number; username: string; name: string; cedula: string; role: RoleCode; subrole: string | null } };
type CloudFile = { name: string; type: 'file' | 'folder' };
type PreRegistration = { id: number; cedula: string; role_code: string; status: string; first_names: string | null; last_names: string | null; is_active: number; created_by: number; created_at: string };

@Component({
  selector: 'app-root',
  imports: [FormsModule, DatePipe],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly storageKey = 'compras-publicas-session';

  username = '';
  password = '';
  remember = false;
  showPassword = false;
  loginMessage = '';
  isSubmitting = false;
  authMode: 'login' | 'recovery' | 'registration' | 'home' = 'login';
  recoveryUsername = '';
  session: SessionData | null = null;
  isRestoringSession = true;
  files: CloudFile[] = [];
  selectedFileRole = 'contratacion_publica';
  allowedExtensions: string[] = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
  isUploading = false;
  uploadMessage = '';
  activeHomeView: 'files' | 'pre-registration' = 'files';
  cedula = '';
  preRegistrationRole = 'operador';
  preRegistrationMessage = '';
  preRegistrations: PreRegistration[] = [];
  preRegistrationPage = 1;
  preRegistrationPages = 1;
  selectedPreRegistration: PreRegistration | null = null;
  showRegistrationInfo = false;
  registrationCedula = '';
  verifiedCedula = '';
  firstNames = '';
  lastNames = '';
  registrationMessage = '';

  ngOnInit(): void {
    const rawSession = localStorage.getItem(this.storageKey);
    if (!rawSession) {
      this.isRestoringSession = false;
      this.navigate('/login');
      return;
    }

    try {
      const session = JSON.parse(rawSession) as SessionData;
      if (session.expiresAt * 1000 <= Date.now()) return this.logout();
      this.http.get(`${this.apiBase}/session`, { headers: new HttpHeaders({ Authorization: `Bearer ${session.token}` }) }).subscribe({
        next: () => { this.session = session; this.authMode = 'home'; this.isRestoringSession = false; this.navigate('/home'); this.loadFiles(); },
        error: () => this.logout(),
      });
    } catch { this.logout(); }
  }

  get sessionRoleLabel(): string {
    const labels: Record<RoleCode, string> = {
      contratacion_publica: 'Contratación Pública', bienes_activos_fijos: 'Bienes y Activos Fijos', contador: 'Contador', director: 'Director', operador: 'Operador',
    };
    return this.session ? labels[this.session.user.role] : '';
  }

  get apiBase(): string { return `http://${window.location.hostname}/compras-publicas-api/api/auth`; }
  get rootApi(): string { return `http://${window.location.hostname}/compras-publicas-api/api`; }
  get fileAccept(): string { return this.allowedExtensions.map((extension) => `.${extension}`).join(','); }
  get canPreRegister(): boolean { return this.session?.user.role === 'contratacion_publica'; }

  openRecovery(): void { this.loginMessage = ''; this.authMode = 'recovery'; }
  openRegistration(): void { this.loginMessage = ''; this.registrationMessage = ''; this.authMode = 'registration'; }
  closeRecovery(): void { this.authMode = 'login'; }
  requestRecovery(): void { /* Pendiente de configurar correo institucional. */ }

  verifyPreRegistration(): void {
    this.registrationMessage = '';
    this.http.get<{ cedula: string; role_code: string }>(`${this.rootApi}/registration/verify?cedula=${encodeURIComponent(this.registrationCedula)}`).subscribe({
      next: ({ cedula, role_code }) => { this.verifiedCedula = cedula; this.preRegistrationRole = role_code; },
      error: (error) => this.registrationMessage = error.error?.message ?? 'No se pudo verificar el pre-registro.',
    });
  }

  completeRegistration(): void {
    this.registrationMessage = '';
    this.http.post(`${this.rootApi}/registration/complete`, { cedula: this.verifiedCedula, firstNames: this.firstNames, lastNames: this.lastNames }).subscribe({
      next: () => this.registrationMessage = 'Datos registrados correctamente. Su cuenta está lista para ser activada.',
      error: (error) => this.registrationMessage = error.error?.message ?? 'No se pudo completar el registro.',
    });
  }

  logout(): void {
    localStorage.removeItem(this.storageKey);
    this.session = null;
    this.password = '';
    this.authMode = 'login';
    this.isRestoringSession = false;
    this.navigate('/login');
  }

  loadFiles(): void {
    if (!this.session) return;
    const role = this.session.user.role === 'contratacion_publica' ? this.selectedFileRole : this.session.user.role;
    this.http.get<{ files: CloudFile[] }>(`${this.rootApi}/documents?role=${role}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: ({ files }) => this.files = files, error: () => this.files = [] });
  }

  loadDocumentConfig(): void {
    if (!this.session) return;
    this.http.get<{ extensions: string[] }>(`${this.rootApi}/documents/config`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: data => this.allowedExtensions = data.extensions });
  }

  uploadFile(event: Event): void { const file = (event.target as HTMLInputElement).files?.[0]; if (file) this.uploadSelectedFile(file); }
  onFileDrop(event: DragEvent): void { event.preventDefault(); const file = event.dataTransfer?.files?.[0]; if (file) this.uploadSelectedFile(file); }
  private uploadSelectedFile(file: File): void { if (!this.session || this.isUploading) return; const extension = file.name.split('.').pop()?.toLowerCase() ?? ''; if (!this.allowedExtensions.includes(extension)) { this.uploadMessage = 'Formato no permitido. Use PDF, Word o Excel.'; return; } this.isUploading = true; this.uploadMessage = ''; const data = new FormData(); data.append('file', file); this.http.post(`${this.rootApi}/documents`, data, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: () => { this.isUploading = false; this.uploadMessage = 'Archivo cargado correctamente.'; this.loadFiles(); }, error: error => { this.isUploading = false; this.uploadMessage = error.error?.message ?? 'No se pudo cargar el archivo.'; } }); }
  deleteFile(name: string): void { if (!this.session) return; this.http.delete(`${this.rootApi}/documents/${encodeURIComponent(name)}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe(() => this.loadFiles()); }

  createPreRegistration(): void {
    if (!this.session) return;
    this.preRegistrationMessage = '';
    this.http.post(`${this.rootApi}/pre-registrations`, { cedula: this.cedula, role: this.preRegistrationRole }, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({
      next: () => { this.preRegistrationMessage = 'Pre-registro creado correctamente.'; this.cedula = ''; this.loadPreRegistrations(1); },
      error: (error) => this.preRegistrationMessage = error.error?.message ?? 'No se pudo crear el pre-registro.',
    });
  }

  loadPreRegistrations(page = this.preRegistrationPage): void {
    if (!this.session || !this.canPreRegister) return;
    this.http.get<{ items: PreRegistration[]; page: number; pages: number }>(`${this.rootApi}/pre-registrations?page=${page}`, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe({ next: data => { this.preRegistrations = data.items; this.preRegistrationPage = data.page; this.preRegistrationPages = data.pages; } });
  }

  togglePreRegistration(id: number): void {
    if (!this.session) return;
    this.http.patch(`${this.rootApi}/pre-registrations/${id}/toggle`, {}, { headers: new HttpHeaders({ Authorization: `Bearer ${this.session.token}` }) }).subscribe(() => this.loadPreRegistrations());
  }

  onSubmit(): void {
    if (this.isSubmitting) return;
    this.isSubmitting = true;
    this.loginMessage = '';
    this.http.post<SessionData>(`${this.apiBase}/login`, { username: this.username, password: this.password }).subscribe({
      next: (session) => {
        this.isSubmitting = false;
        this.session = session;
        localStorage.setItem(this.storageKey, JSON.stringify(session));
        this.authMode = 'home';
        this.navigate('/home');
        this.loadFiles();
        this.loadDocumentConfig();
        this.loadPreRegistrations();
      },
      error: () => { this.isSubmitting = false; this.loginMessage = 'invalid-credentials'; },
    });
  }

  private navigate(path: '/login' | '/home'): void {
    if (window.location.pathname !== path) window.history.replaceState({}, '', path);
  }
}
