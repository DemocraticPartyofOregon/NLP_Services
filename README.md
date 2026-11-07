# NLP_Services
Drupal Module for support of Neighborhood Leader Program

## Moving or copying a same-name repository into this organization

If you have a repository with the same name in your private account, use one of these approaches:

### Option 1: Transfer ownership (keeps history, issues, and PRs)
1. In your private repository, go to **Settings** → **General**.
2. Under **Danger Zone**, choose **Transfer ownership**.
3. Enter the target owner as `DemocraticPartyofOregon`.
4. Confirm the repository name and complete the transfer.

### Option 2: Copy/mirror into this organization (keeps commit history)
1. Create an empty `NLP_Services` repository under `DemocraticPartyofOregon`.
2. From your local clone of the private repository, run:

```bash
git remote add new-origin git@github.com:DemocraticPartyofOregon/NLP_Services.git
git push --mirror new-origin
```

If the destination repository already has commits (for example, a default README), push branches/tags selectively instead of `--mirror`.
