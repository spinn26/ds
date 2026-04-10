<div style="background: #f59e0b; color: #000; padding: 8px 16px; text-align: center; font-weight: 600;">
    Вы вошли как: {{ auth()->user()->email }}
    &nbsp;&mdash;&nbsp;
    <a href="{{ route('impersonate.leave') }}" style="color: #000; text-decoration: underline;">
        Вернуться в свой аккаунт
    </a>
</div>
