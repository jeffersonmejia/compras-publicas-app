import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Component, inject, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';

type RoleCode = 'contratacion_publica' | 'bienes_activos_fijos' | 'contador' | 'director' | 'operador';
type SessionData = { token: string; expiresAt: number; user: { username: string; role: RoleCode; subrole: string | null } };

@Component({
  selector: 'app-root',
  imports: [FormsModule],
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
  authMode: 'login' | 'recovery' | 'home' = 'login';
  recoveryUsername = '';
  session: SessionData | null = null;
  isRestoringSession = true;

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
        next: () => { this.session = session; this.authMode = 'home'; this.isRestoringSession = false; this.navigate('/home'); },
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

  openRecovery(): void { this.loginMessage = ''; this.authMode = 'recovery'; }
  closeRecovery(): void { this.authMode = 'login'; }
  requestRecovery(): void { /* Pendiente de configurar correo institucional. */ }

  logout(): void {
    localStorage.removeItem(this.storageKey);
    this.session = null;
    this.password = '';
    this.authMode = 'login';
    this.isRestoringSession = false;
    this.navigate('/login');
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
      },
      error: () => { this.isSubmitting = false; this.loginMessage = 'invalid-credentials'; },
    });
  }

  private navigate(path: '/login' | '/home'): void {
    if (window.location.pathname !== path) window.history.replaceState({}, '', path);
  }
}
