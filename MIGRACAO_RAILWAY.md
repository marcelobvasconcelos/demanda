# 🚂 Migração do Render para Railway (com docker-compose)

## Por que migrar?

O Railway suporta `docker-compose.yml` no plano free, permitindo rodar MySQL e PHP no mesmo projeto, mantendo a filosofia de containers.

---

## Passo a Passo da Migração

### 1. Criar conta no Railway
1. Acesse: https://railway.app
2. Login com GitHub
3. Você ganha **$5 de crédito grátis/mês**

### 2. Criar novo projeto
1. No dashboard, clique em **"New Project"**
2. Selecione **"Deploy from GitHub repo"**
3. Conecte sua conta GitHub
4. Selecione o repositório `demanda`

### 3. Railway detecta automaticamente
- ✅ Encontra o `docker-compose.yml`
- ✅ Cria serviço `web` (PHP)
- ✅ Cria serviço `db` (MySQL)
- ✅ Configura networking automático

### 4. Configurar variáveis de ambiente
No Railway, vá em `web` → Variables:

```
GOOGLE_APPLICATION_CREDENTIALS=/var/www/html/firebase_credenciais.json
DB_HOST=db
DB_PORT=3306
DB_DATABASE=costureira_db
DB_USERNAME=costureira_user
DB_PASSWORD=costureira_pass
```

### 5. Adicionar credenciais Firebase
1. Vá em `web` → Settings → Secrets
2. Adicione o conteúdo de `firebase_credenciais.json`

### 6. Deploy
- Railway faz deploy automático
- Aguarde ~2-3 minutos
- Acesse a URL gerada

---

## Comparação: Render vs Railway

| Recurso | Render Free | Railway Free |
|---------|-------------|--------------|
| docker-compose | ❌ Não | ✅ Sim |
| MySQL incluído | ❌ Não | ✅ Sim |
| Crédito mensal | - | $5 |
| Persistência dados | ❌ Efêmera | ✅ Volumes |
| Deploy automático | ✅ Sim | ✅ Sim |
| Custom domain | ✅ Sim | ✅ Sim |

---

## Manter Render + MySQL externo

Se preferir manter no Render, a arquitetura fica:

```
┌─────────────────┐
│  Render (Free)  │
│   Container PHP │
│                 │
│  ┌──────────┐   │
│  │ index.php│   │
│  │ atelie.php│  │
│  └────┬─────┘   │
└───────┼─────────┘
        │
        │ DB_HOST=railway-host
        │
        ▼
┌─────────────────┐
│ Railway (Free)  │
│   MySQL 8.0     │
│                 │
│  ┌──────────┐   │
│  │ Tables   │   │
│  │ - lotes  │   │
│  │ - atelie │   │
│  └──────────┘   │
└─────────────────┘
```

**Vantagens desta arquitetura:**
- ✅ Banco separado = mais seguro
- ✅ Backup independente
- ✅ Pode escalar PHP sem afetar banco
- ✅ Dados persistem mesmo recriando container

---

## Decisão

**Opção A: Migrar para Railway** (Recomendado)
- Mantém filosofia de containers
- Tudo em um lugar
- docker-compose funciona

**Opção B: Manter Render + Railway MySQL**
- Arquitetura mais profissional
- Separação de responsabilidades
- Mais resiliente

**Qual você prefere?**
