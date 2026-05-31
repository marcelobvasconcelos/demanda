# Comandos para enviar ao GitHub

## 1. Verificar status dos arquivos
git status

## 2. Adicionar todos os arquivos novos e modificados
git add .

## 3. Fazer commit com mensagem descritiva
git commit -m "feat: Implementar módulo Ateliê Sob Medida completo

- Adicionar arquivo principal atelie_sob_medida.php
- Criar views para pedidos, clientes e catálogo
- Implementar CRUD completo de clientes com medidas
- Implementar CRUD de catálogo de serviços
- Implementar gestão de pedidos com múltiplos itens
- Adicionar dashboard financeiro do módulo
- Integrar botão de acesso no menu principal
- Adicionar documentação do módulo (ATELIE_README.md)
- Sistema 100% MySQL sem interação com Firebase"

## 4. Enviar para o GitHub
git push origin main

## OU se estiver em outra branch:
git push origin nome-da-sua-branch

## 5. Se for o primeiro push do repositório:
git push -u origin main

## ALTERNATIVA: Criar uma branch específica para esta feature
git checkout -b feature/atelie-sob-medida
git add .
git commit -m "feat: Implementar módulo Ateliê Sob Medida completo"
git push origin feature/atelie-sob-medida

## Depois você pode fazer um Pull Request no GitHub para mesclar na main
